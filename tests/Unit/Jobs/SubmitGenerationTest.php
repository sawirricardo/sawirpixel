<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\PollGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

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
