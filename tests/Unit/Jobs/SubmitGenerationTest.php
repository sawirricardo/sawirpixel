<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\PollGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    config()->set('services.pixelforge.key', 'pf_test_key');
});

test('submits with the stored idempotency key, records the pixelforge id and starts polling', function (): void {
    Queue::fake([PollGeneration::class]);
    Http::fake(['pixelforge.promptedit.dev/v1/generations' => Http::response(pixelforgeGenerationPayload('queued'), 202)]);
    $generation = Generation::factory()->create(['reference_images' => ['https://example.com/ref.png']]);

    (new SubmitGeneration($generation))->handle(resolve(PixelforgeClient::class));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Queued);
    expect($generation->pixelforge_id)->toBe('gen_1');
    expect($generation->submitted_at)->not->toBeNull();
    Http::assertSent(fn (Request $request): bool => $request->hasHeader('Idempotency-Key', $generation->idempotency_key)
        && $request['metadata'] === ['generation_id' => (string) $generation->id]
        && $request['reference_images'] === ['https://example.com/ref.png']);
    Queue::assertPushed(PollGeneration::class, fn (PollGeneration $job): bool => $job->generation->is($generation));
});

test('retries later when pixelforge is rate limited or overloaded', function (int $status, array $headers, int $delay): void {
    Queue::fake([PollGeneration::class]);
    Http::fake(['pixelforge.promptedit.dev/v1/generations' => Http::response(['error' => ['message' => 'Busy.']], $status, $headers)]);
    $generation = Generation::factory()->create();
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();

    $job->handle(resolve(PixelforgeClient::class));

    $job->assertReleased(delay: $delay);

    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending);
    Queue::assertNothingPushed();
})->with([
    'concurrency limit' => [429, ['Retry-After' => '5'], 5],
    'rate limit' => [429, ['Retry-After' => '17'], 17],
    'overloaded without retry-after' => [503, [], 5],
]);

test('fails a rejected prompt without charging for it', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/generations' => Http::response([
        'error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Prompt blocked.'],
    ], 400)]);
    $generation = Generation::factory()->create();

    (new SubmitGeneration($generation))->handle(resolve(PixelforgeClient::class));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed);
    expect($generation->error_code)->toBe('content_policy_violation');
    expect($generation->charge)->toBe('0.0000000000');
});

test('does nothing for a generation that was already submitted', function (): void {
    Http::fake();
    $generation = Generation::factory()->queued()->create();

    (new SubmitGeneration($generation))->handle(resolve(PixelforgeClient::class));

    Http::assertNothingSent();
});

test('fails the generation without charging once retries are exhausted', function (): void {
    $generation = Generation::factory()->create();

    (new SubmitGeneration($generation))->failed(new RuntimeException('Connection refused'));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed);
    expect($generation->error_code)->toBe('submission_failed');
    expect($generation->charge)->toBe('0.0000000000');
});

test('waits for a free slot while pixelforge has the maximum generations in flight', function (): void {
    Http::fake();
    Generation::factory()->queued()->count(2)->create();
    Generation::factory()->queued()->create(['status' => GenerationStatus::Processing]);
    $generation = Generation::factory()->create();
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();

    $job->handle(resolve(PixelforgeClient::class));

    $job->assertReleased(delay: SubmitGeneration::WAIT_FOR_SLOT_SECONDS);
    Http::assertNothingSent();
    expect($generation->refresh()->status)->toBe(GenerationStatus::Pending);
});

test('does not count long-unfinished generations as in flight', function (): void {
    Queue::fake([PollGeneration::class]);
    Http::fake(['pixelforge.promptedit.dev/v1/generations' => Http::response(pixelforgeGenerationPayload('queued'), 202)]);
    Generation::factory()->queued()->count(3)->create(['submitted_at' => now()->subHour()]);
    $generation = Generation::factory()->create();

    (new SubmitGeneration($generation))->handle(resolve(PixelforgeClient::class));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

test('waits while another worker is deciding whether a slot is free', function (): void {
    Http::fake();
    $lock = Cache::lock('pixelforge:submit', 30);
    $lock->get();

    $job = (new SubmitGeneration(Generation::factory()->create()))->withFakeQueueInteractions();

    $job->handle(resolve(PixelforgeClient::class));

    $job->assertReleased(delay: 1);
    Http::assertNothingSent();
    $lock->release();
});

test('fails with a clear message instead of retrying once the daily limit is reached', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/generations' => Http::response([
        'error' => ['type' => 'rate_limit_error', 'code' => 'daily_limit_exceeded', 'message' => 'Daily limit reached.'],
    ], 429)]);
    $generation = Generation::factory()->create();
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();

    $job->handle(resolve(PixelforgeClient::class));

    $job->assertNotReleased();

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed);
    expect($generation->error_code)->toBe('daily_limit_exceeded');
    expect($generation->charge)->toBe('0.0000000000');
});

test('shares the pixelforge request budget, releasing instead of calling the api once it is spent', function (): void {
    Http::fake();
    RateLimiter::for('pixelforge', fn (): Limit => Limit::perMinute(1));
    $run = fn (SubmitGeneration $job) => (new Pipeline(app()))
        ->send($job)
        ->through($job->middleware())
        ->then(fn (SubmitGeneration $job) => $job->handle(resolve(PixelforgeClient::class)));

    $run((new SubmitGeneration(Generation::factory()->queued()->create()))->withFakeQueueInteractions());
    $limited = (new SubmitGeneration(Generation::factory()->create()))->withFakeQueueInteractions();
    $run($limited);

    $limited->assertReleased();
    Http::assertNothingSent();
});
