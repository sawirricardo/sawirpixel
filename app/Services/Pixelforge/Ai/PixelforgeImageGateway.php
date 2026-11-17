<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Ai;

use App\Enums\Pixelforge\GenerationStatus;
use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;
use App\Exceptions\Pixelforge\GenerationFailedException;
use App\Exceptions\Pixelforge\GenerationTimedOutException;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Services\Pixelforge\Data\Generation;
use App\Services\Pixelforge\Data\GenerationRequest;
use App\Services\Pixelforge\Data\GenerationUsage;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Exceptions\RateLimitedException;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Files\RemoteImage;
use Laravel\Ai\Responses\Data\GeneratedImage;
use Laravel\Ai\Responses\Data\ImageUsage;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\ImageResponse;

/**
 * Adapts Pixelforge's asynchronous API to laravel/ai's synchronous image contract by polling until the
 * generation finishes. It blocks for up to the timeout, so call it from queued jobs or the CLI.
 */
final readonly class PixelforgeImageGateway implements ImageGateway
{
    private const array SUPPORTED_PROVIDER_OPTIONS = ['idempotency_key', 'metadata'];

    private const int MAX_REFERENCE_IMAGES = 4;

    public function __construct(
        private PixelforgeClient $client,
        private int $pollIntervalSeconds = 3,
        private int $defaultTimeoutSeconds = 180,
    ) {}

    /**
     * @param  array<Image>  $attachments  Only remote images are supported; they are sent as reference image URLs.
     * @param  'low'|'medium'|'high'|null  $quality
     * @param  array<string, mixed>  $providerOptions  Supports "idempotency_key" (string) and "metadata" (array<string, string>).
     *
     * @throws InvalidArgumentException
     * @throws GenerationFailedException
     * @throws GenerationTimedOutException
     * @throws PixelforgeException
     * @throws RateLimitedException
     * @throws ProviderOverloadedException
     */
    public function generateImage(
        ImageProvider $provider,
        string $model,
        string $prompt,
        array $attachments = [],
        ?string $size = null,
        ?string $quality = null,
        ?int $timeout = null,
        array $providerOptions = [],
    ): ImageResponse {
        $request = new GenerationRequest(
            prompt: $prompt,
            size: ImageSize::fromSizeOrAspectRatio($size),
            quality: ImageQuality::from($quality ?? ImageQuality::Medium->value),
            referenceImages: $this->referenceImageUrls($attachments),
            metadata: $this->metadata($providerOptions),
        );

        $generation = $this->waitForCompletion(
            $this->create($provider, $request, $this->idempotencyKey($providerOptions)),
            $timeout ?? $this->defaultTimeoutSeconds,
        );

        throw_if($generation->status === GenerationStatus::Failed || $generation->imageUrl === null, GenerationFailedException::class, $generation);

        return new ImageResponse(
            new Collection([new GeneratedImage(base64_encode($this->client->downloadImage($generation->imageUrl)), 'image/png')]),
            $this->usage($generation),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * @throws RateLimitedException
     * @throws ProviderOverloadedException
     * @throws PixelforgeException
     */
    private function create(ImageProvider $provider, GenerationRequest $request, string $idempotencyKey): Generation
    {
        try {
            return $this->client->createGeneration($request, $idempotencyKey);
        } catch (PixelforgeException $pixelforgeException) {
            throw match (true) {
                $pixelforgeException->isRateLimited() => RateLimitedException::forProvider($provider->name(), $pixelforgeException->status, $pixelforgeException),
                $pixelforgeException->isOverloaded() => ProviderOverloadedException::forProvider($provider->name(), $pixelforgeException->status, $pixelforgeException),
                default => $pixelforgeException,
            };
        }
    }

    /**
     * Poll until the generation is final. Transient poll errors (429/5xx) are waited out rather than thrown,
     * because the generation is already accepted and will be billed if it succeeds.
     *
     * @throws GenerationTimedOutException
     * @throws PixelforgeException
     */
    private function waitForCompletion(Generation $generation, int $timeoutSeconds): Generation
    {
        $remainingSeconds = $timeoutSeconds;

        while (! $generation->status->isFinal()) {
            if ($remainingSeconds <= 0) {
                throw new GenerationTimedOutException($generation->id);
            }

            $waitSeconds = $this->pollIntervalSeconds;

            try {
                Sleep::for($waitSeconds)->seconds();
                $generation = $this->client->getGeneration($generation->id);
            } catch (PixelforgeException $exception) {
                throw_unless($exception->isTransient(), $exception);

                if ($exception->retryAfter !== null) {
                    $waitSeconds += $exception->retryAfter;
                    Sleep::for($exception->retryAfter)->seconds();
                }
            }

            $remainingSeconds -= $waitSeconds;
        }

        return $generation;
    }

    /**
     * @param  array<Image>  $attachments
     * @return list<string>
     *
     * @throws InvalidArgumentException
     */
    private function referenceImageUrls(array $attachments): array
    {
        throw_if(
            count($attachments) > self::MAX_REFERENCE_IMAGES,
            InvalidArgumentException::class,
            sprintf('Pixelforge accepts at most %d reference images.', self::MAX_REFERENCE_IMAGES),
        );

        return array_values(array_map(function (Image $attachment): string {
            throw_unless(
                $attachment instanceof RemoteImage,
                InvalidArgumentException::class,
                sprintf('Pixelforge only accepts remote image URLs as reference images; got [%s].', $attachment::class),
            );

            return $attachment->url;
        }, $attachments));
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     * @return array<string, string>
     *
     * @throws InvalidArgumentException
     */
    private function metadata(array $providerOptions): array
    {
        $unsupported = array_diff(array_keys($providerOptions), self::SUPPORTED_PROVIDER_OPTIONS);

        throw_if(
            $unsupported !== [],
            InvalidArgumentException::class,
            sprintf('Unsupported Pixelforge provider options: %s.', implode(', ', $unsupported)),
        );

        $metadata = $providerOptions['metadata'] ?? [];
        $invalid = new InvalidArgumentException('Pixelforge metadata must be an array of string keys and string values.');

        throw_unless(is_array($metadata), $invalid);

        $validated = [];

        foreach ($metadata as $key => $value) {
            throw_unless(is_string($key) && is_string($value), $invalid);

            $validated[$key] = $value;
        }

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $providerOptions
     */
    private function idempotencyKey(array $providerOptions): string
    {
        $key = $providerOptions['idempotency_key'] ?? null;

        return is_string($key) && $key !== '' ? $key : (string) Str::uuid7();
    }

    private function usage(Generation $generation): ImageUsage
    {
        $usage = $generation->usage;

        return $usage instanceof GenerationUsage ? new ImageUsage(
            inputTokens: $usage->inputTextTokens + $usage->inputImageTokens,
            outputTokens: $usage->outputTokens,
            reasoningTokens: $usage->reasoningTokens,
            imageInputTokens: $usage->inputImageTokens,
            imageOutputTokens: $usage->outputTokens,
        ) : new ImageUsage;
    }
}
