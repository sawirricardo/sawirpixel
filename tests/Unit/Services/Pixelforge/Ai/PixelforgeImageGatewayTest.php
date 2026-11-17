<?php

declare(strict_types=1);

use App\Exceptions\Pixelforge\GenerationFailedException;
use App\Exceptions\Pixelforge\GenerationTimedOutException;
use App\Exceptions\Pixelforge\PixelforgeException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Laravel\Ai\Ai;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Image as ImageFile;
use Laravel\Ai\Image;
use Laravel\Ai\Prompts\ImagePrompt;
use Laravel\Ai\Responses\ImageResponse;

beforeEach(function (): void {
    config()->set('services.pixelforge.key', 'pf_test_key');
    config()->set('ai.providers.pixelforge.poll_interval', 3);
    config()->set('ai.providers.pixelforge.timeout', 180);
});

test('polls until the generation succeeds and returns the downloaded image with its token usage', function (): void {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response(pixelforgeGenerationPayload('queued'), 202),
        'pixelforge.promptedit.dev/v1/generations/gen_1' => Http::sequence()
            ->push(pixelforgeGenerationPayload('processing'))
            ->push(pixelforgeGenerationPayload('succeeded')),
        'pixelforge.promptedit.dev/images/gen_1.png*' => Http::response('png-bytes'),
    ]);

    $response = Image::of('A lighthouse at dawn')->landscape()->quality('high')->generate(provider: 'pixelforge');

    expect($response->firstImage()->image)->toBe(base64_encode('png-bytes'));
    expect($response->firstImage()->mime())->toBe('image/png');
    expect($response->usage->inputTokens)->toBe(777);
    expect($response->usage->outputTokens)->toBe(6450);
    expect($response->usage->reasoningTokens)->toBe(3184);
    expect($response->usage->imageInputTokens)->toBe(765);
    expect($response->meta->provider)->toBe('pixelforge');
    Sleep::assertSequence([Sleep::for(3)->seconds(), Sleep::for(3)->seconds()]);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->data() === ['prompt' => 'A lighthouse at dawn', 'size' => '1536x1024', 'quality' => 'high']
        && $request->hasHeader('Idempotency-Key'));
});

test('sends remote reference images, metadata and the given idempotency key', function (): void {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response(pixelforgeGenerationPayload('succeeded'), 202),
        'pixelforge.promptedit.dev/images/gen_1.png*' => Http::response('png-bytes'),
    ]);

    Image::of('A lighthouse at dawn')
        ->attachments([ImageFile::fromUrl('https://example.com/ref.png')])
        ->withProviderOptions(['idempotency_key' => 'order-1042', 'metadata' => ['order' => '1042']])
        ->generate(provider: 'pixelforge');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Idempotency-Key', 'order-1042')
        && $request['reference_images'] === ['https://example.com/ref.png']
        && $request['metadata'] === ['order' => '1042']
        && $request['size'] === '1024x1024'
        && $request['quality'] === 'medium');
});

test('throws with the provider error when the generation fails', function (): void {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response(pixelforgeGenerationPayload('queued'), 202),
        'pixelforge.promptedit.dev/v1/generations/gen_1' => Http::response(pixelforgeGenerationPayload('failed')),
    ]);

    expect(fn (): ImageResponse => Image::of('[fail] A lighthouse')->generate(provider: 'pixelforge'))
        ->toThrow(GenerationFailedException::class, 'Pixelforge generation [gen_1] failed: No image could be produced.');
});

test('throws when a succeeded generation has no image url', function (): void {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response(pixelforgeGenerationPayload('succeeded', ['image_url' => null]), 202),
    ]);

    expect(fn (): ImageResponse => Image::of('A lighthouse')->generate(provider: 'pixelforge'))
        ->toThrow(GenerationFailedException::class, 'Pixelforge generation [gen_1] failed: unknown error');
});

test('stops polling and reports the generation id when it does not finish within the timeout', function (): void {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response(pixelforgeGenerationPayload('queued'), 202),
        'pixelforge.promptedit.dev/v1/generations/gen_1' => Http::response(pixelforgeGenerationPayload('processing')),
    ]);

    expect(fn (): ImageResponse => Image::of('[slow] A lighthouse')->timeout(6)->generate(provider: 'pixelforge'))
        ->toThrow(GenerationTimedOutException::class, 'Pixelforge generation [gen_1] did not finish in time');
    Http::assertSentCount(3);
});

test('waits out a rate limit while polling instead of failing an accepted generation', function (): void {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response(pixelforgeGenerationPayload('queued'), 202),
        'pixelforge.promptedit.dev/v1/generations/gen_1' => Http::sequence()
            ->push(['error' => ['type' => 'rate_limit_error', 'code' => 'rate_limit_exceeded', 'message' => 'Slow down.']], 429, ['Retry-After' => '5'])
            ->push(['error' => ['type' => 'api_error', 'code' => 'overloaded', 'message' => 'Busy.']], 503)
            ->push(pixelforgeGenerationPayload('succeeded')),
        'pixelforge.promptedit.dev/images/gen_1.png*' => Http::response('png-bytes'),
    ]);

    $response = Image::of('A lighthouse')->generate(provider: 'pixelforge');

    expect($response->firstImage()->image)->toBe(base64_encode('png-bytes'));
    Sleep::assertSequence([
        Sleep::for(3)->seconds(),
        Sleep::for(5)->seconds(),
        Sleep::for(3)->seconds(),
        Sleep::for(3)->seconds(),
    ]);
});

test('rethrows a non-transient error while polling', function (): void {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response(pixelforgeGenerationPayload('queued'), 202),
        'pixelforge.promptedit.dev/v1/generations/gen_1' => Http::response(
            ['error' => ['type' => 'invalid_request_error', 'code' => 'not_found', 'message' => 'Generation not found.']],
            404,
        ),
    ]);

    expect(fn (): ImageResponse => Image::of('A lighthouse')->generate(provider: 'pixelforge'))
        ->toThrow(PixelforgeException::class, 'Generation not found.');
});

test('maps submission errors that allow failover to laravel/ai exceptions', function (int $status, string $exception): void {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response(['error' => ['message' => 'Try later.']], $status),
    ]);

    expect(fn (): ImageResponse => Image::of('A lighthouse')->generate(provider: 'pixelforge'))->toThrow($exception);
})->with([
    'rate limited' => [429, RateLimitedException::class],
    'overloaded' => [503, ProviderOverloadedException::class],
]);

test('throws the provider error for a rejected submission', function (): void {
    Http::fake([
        'pixelforge.promptedit.dev/v1/generations' => Http::response(
            ['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Prompt blocked.']],
            400,
        ),
    ]);

    expect(fn (): ImageResponse => Image::of('[blocked] A lighthouse')->generate(provider: 'pixelforge'))
        ->toThrow(PixelforgeException::class, 'Prompt blocked.');
});

test('rejects invalid input before calling pixelforge', function (Closure $build, string $message): void {
    Http::fake();

    expect(fn () => $build(Image::of('A lighthouse'))->generate(provider: 'pixelforge'))
        ->toThrow(InvalidArgumentException::class, $message);
    Http::assertNothingSent();
})->with([
    'local reference image' => [
        fn ($image) => $image->attachments([ImageFile::fromBase64(base64_encode('png'), 'image/png')]),
        'Pixelforge only accepts remote image URLs as reference images',
    ],
    'five reference images' => [
        fn ($image) => $image->attachments(array_fill(0, 5, ImageFile::fromUrl('https://example.com/ref.png'))),
        'Pixelforge accepts at most 4 reference images.',
    ],
    'unsupported size' => [
        fn ($image) => $image->size('512x512'),
        'Pixelforge does not support the image size [512x512].',
    ],
    'unsupported provider option' => [
        fn ($image) => $image->withProviderOptions(['model' => 'gpt-image']),
        'Unsupported Pixelforge provider options: model.',
    ],
    'metadata that is not an array' => [
        fn ($image) => $image->withProviderOptions(['metadata' => 'order-1042']),
        'Pixelforge metadata must be an array of string keys and string values.',
    ],
    'metadata with a non-string value' => [
        fn ($image) => $image->withProviderOptions(['metadata' => ['order' => 1042]]),
        'Pixelforge metadata must be an array of string keys and string values.',
    ],
]);

test('records the prompt against the pixelforge provider when images are faked', function (): void {
    Image::fake();
    Http::fake();

    Image::of('A lighthouse')->portrait()->generate(provider: 'pixelforge');

    Image::assertGenerated(fn (ImagePrompt $prompt): bool => $prompt->contains('lighthouse') && $prompt->provider->name() === 'pixelforge');
    Http::assertNothingSent();
});

test('normalizes laravel/ai sizes and qualities to pixelforge options', function (): void {
    $provider = Ai::imageProvider('pixelforge');

    expect($provider->defaultImageOptions('2:3', 'low'))->toBe(['size' => '1024x1536', 'quality' => 'low']);
    expect($provider->defaultImageOptions())->toBe(['size' => '1024x1024', 'quality' => 'medium']);
    expect($provider->defaultImageModel())->toBe('pixelforge');
});
