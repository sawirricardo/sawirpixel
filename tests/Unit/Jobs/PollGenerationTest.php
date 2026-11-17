<?php

declare(strict_types=1);

use App\Actions\ApplyPixelforgeGeneration;
use App\Enums\GenerationStatus;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Jobs\PollGeneration;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.pixelforge.key', 'pf_test_key');
});

function runPollGeneration(PollGeneration $job): void
{
    $job->handle(resolve(PixelforgeClient::class), resolve(ApplyPixelforgeGeneration::class));
}

test('records the result once the generation has finished', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/generations/gen_1' => Http::response(pixelforgeGenerationPayload('succeeded'))]);
    $generation = Generation::factory()->queued()->create(['pixelforge_id' => 'gen_1']);
    $job = (new PollGeneration($generation))->withFakeQueueInteractions();

    runPollGeneration($job);

    $job->assertNotReleased();
    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
    expect($generation->charge)->toBe('0.3818875000');
});

test('checks again later while the generation is still running', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/generations/gen_1' => Http::response(pixelforgeGenerationPayload('processing'))]);
    $generation = Generation::factory()->queued()->create(['pixelforge_id' => 'gen_1']);
    $job = (new PollGeneration($generation))->withFakeQueueInteractions();

    runPollGeneration($job);

    $job->assertReleased(delay: PollGeneration::INTERVAL_SECONDS);
    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
});

test('waits out a rate limit before checking again', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/generations/gen_1' => Http::response(['error' => ['message' => 'Slow down.']], 429, ['Retry-After' => '30'])]);
    $generation = Generation::factory()->queued()->create(['pixelforge_id' => 'gen_1']);
    $job = (new PollGeneration($generation))->withFakeQueueInteractions();

    runPollGeneration($job);

    $job->assertReleased(delay: 30);
});

test('fails the job instead of retrying an error that retrying will not fix', function (): void {
    Http::fake(['pixelforge.promptedit.dev/v1/generations/gen_1' => Http::response(['error' => ['message' => 'Not found.']], 404)]);
    $generation = Generation::factory()->queued()->create(['pixelforge_id' => 'gen_1']);
    $job = (new PollGeneration($generation))->withFakeQueueInteractions();

    runPollGeneration($job);

    $job->assertFailedWith(PixelforgeException::class);
    $job->assertNotReleased();
});

test('does nothing for a generation that has not been accepted yet', function (): void {
    Http::fake();
    $generation = Generation::factory()->create();

    runPollGeneration(new PollGeneration($generation));

    Http::assertNothingSent();
});

test('stops once a webhook has already finished the generation', function (): void {
    Http::fake();
    $generation = Generation::factory()->queued()->create(['pixelforge_id' => 'gen_1', 'status' => GenerationStatus::Succeeded]);

    runPollGeneration(new PollGeneration($generation));

    Http::assertNothingSent();
});
