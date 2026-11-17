<?php

declare(strict_types=1);

use App\Enums\Pixelforge\GenerationStatus;
use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\Data\GenerationRequest;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.pixelforge.key', 'pf_test_key');
});

test('creates a generation with the documented body, bearer token and idempotency key', function (): void {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response([
            'id' => 'gen_1',
            'status' => 'queued',
            'size' => '1536x1024',
            'quality' => 'high',
            'image_url' => null,
            'usage' => null,
            'rates' => null,
            'error' => null,
            'created_at' => '2026-11-04T14:03:11Z',
        ], 202),
    ]);

    $generation = resolve(PixelforgeClient::class)->createGeneration(
        new GenerationRequest(
            prompt: 'A lighthouse at dawn',
            size: ImageSize::Landscape,
            quality: ImageQuality::High,
            referenceImages: ['https://example.com/ref.png'],
            metadata: ['order' => '1042'],
        ),
        'order-1042',
    );

    expect($generation->id)->toBe('gen_1');
    expect($generation->status)->toBe(GenerationStatus::Queued);
    expect($generation->usage)->toBeNull();
    expect($generation->createdAt?->toIso8601ZuluString())->toBe('2026-11-04T14:03:11Z');
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key')
        && $request->hasHeader('Idempotency-Key', 'order-1042')
        && $request->data() === [
            'prompt' => 'A lighthouse at dawn',
            'size' => '1536x1024',
            'quality' => 'high',
            'reference_images' => ['https://example.com/ref.png'],
            'metadata' => ['order' => '1042'],
        ]);
});

test('parses usage, decimal rates and the signed image url of a succeeded generation', function (): void {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations/gen_1' => Http::response([
            'id' => 'gen_1',
            'status' => 'succeeded',
            'size' => '1024x1024',
            'quality' => 'medium',
            'image_url' => 'https://pixelforge.promptedit.dev/images/gen_1.png?signature=abc',
            'image_url_expires_at' => '2026-11-05T14:03:29Z',
            'usage' => [
                'input_text_tokens' => 12,
                'input_image_tokens' => 765,
                'output_tokens' => 1100,
                'reasoning_tokens' => 300,
                'total_tokens' => 2177,
            ],
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'error' => null,
            'completed_at' => '2026-11-04T14:03:29Z',
        ]),
    ]);

    $generation = resolve(PixelforgeClient::class)->getGeneration('gen_1');

    expect($generation->imageUrl)->toBe('https://pixelforge.promptedit.dev/images/gen_1.png?signature=abc');
    expect($generation->imageUrlExpiresAt?->toIso8601ZuluString())->toBe('2026-11-05T14:03:29Z');
    expect($generation->usage?->inputImageTokens)->toBe(765);
    expect($generation->usage?->totalTokens)->toBe(2177);
    expect($generation->rates?->reasoningTokens)->toBe('12.50');
    expect($generation->rates?->outputTokens)->toBe('40.00');
});

test('parses the error of a failed generation', function (): void {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations/gen_1' => Http::response([
            'id' => 'gen_1',
            'status' => 'failed',
            'size' => '1024x1024',
            'quality' => 'medium',
            'error' => ['code' => 'generation_failed', 'message' => 'No image could be produced.'],
        ]),
    ]);

    $generation = resolve(PixelforgeClient::class)->getGeneration('gen_1');

    expect($generation->status)->toBe(GenerationStatus::Failed);
    expect($generation->error?->code)->toBe('generation_failed');
    expect($generation->error?->message)->toBe('No image could be produced.');
});

test('throws the api error code, type, param and retry-after for an error response', function (): void {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations/gen_1' => Http::response([
            'error' => [
                'type' => 'rate_limit_error',
                'code' => 'concurrency_limit_exceeded',
                'message' => 'Too many generations in progress.',
                'param' => 'prompt',
            ],
        ], 429, ['Retry-After' => '5']),
    ]);

    try {
        resolve(PixelforgeClient::class)->getGeneration('gen_1');
        $this->fail('Expected a PixelforgeException.');
    } catch (PixelforgeException $pixelforgeException) {
        expect($pixelforgeException->getMessage())->toBe('Too many generations in progress.');
        expect($pixelforgeException->status)->toBe(429);
        expect($pixelforgeException->errorCode)->toBe('concurrency_limit_exceeded');
        expect($pixelforgeException->errorType)->toBe('rate_limit_error');
        expect($pixelforgeException->param)->toBe('prompt');
        expect($pixelforgeException->retryAfter)->toBe(5);
        expect($pixelforgeException->isTransient())->toBeTrue();
    }
});

test('falls back to a generic message when the error body is not json', function (): void {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations/gen_1' => Http::response('<html>Bad gateway</html>', 502),
    ]);

    try {
        resolve(PixelforgeClient::class)->getGeneration('gen_1');
        $this->fail('Expected a PixelforgeException.');
    } catch (PixelforgeException $pixelforgeException) {
        expect($pixelforgeException->getMessage())->toBe('Pixelforge request failed with HTTP 502.');
        expect($pixelforgeException->errorCode)->toBeNull();
        expect($pixelforgeException->retryAfter)->toBeNull();
        expect($pixelforgeException->isTransient())->toBeTrue();
    }
});

test('does not treat a client error as transient', function (): void {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations/gen_missing' => Http::response([
            'error' => ['type' => 'invalid_request_error', 'code' => 'not_found', 'message' => 'Not found.'],
        ], 404),
    ]);

    try {
        resolve(PixelforgeClient::class)->getGeneration('gen_missing');
        $this->fail('Expected a PixelforgeException.');
    } catch (PixelforgeException $pixelforgeException) {
        expect($pixelforgeException->status)->toBe(404);
        expect($pixelforgeException->isTransient())->toBeFalse();
    }
});

test('downloads an image without sending the api key', function (): void {
    Http::fake([
        'pixelforge.promptedit.dev/images/gen_1.png*' => Http::response('png-bytes'),
    ]);

    $body = resolve(PixelforgeClient::class)->downloadImage('https://pixelforge.promptedit.dev/images/gen_1.png?signature=abc');

    expect($body)->toBe('png-bytes');
    Http::assertSent(fn (Request $request): bool => ! $request->hasHeader('Authorization'));
});

test('refuses to call the api when no api key is configured', function (): void {
    config()->set('services.pixelforge.key');
    Http::fake();

    expect(fn () => resolve(PixelforgeClient::class)->getGeneration('gen_1'))
        ->toThrow(RuntimeException::class, 'The Pixelforge API key is not configured. Set PIXELFORGE_API_KEY.');
    Http::assertNothingSent();
});
