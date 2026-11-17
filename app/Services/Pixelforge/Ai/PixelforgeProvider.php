<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Ai;

use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Fluent;
use Laravel\Ai\Contracts\Gateway\ImageGateway;
use Laravel\Ai\Contracts\Providers\ImageProvider;
use Laravel\Ai\Providers\Concerns\GeneratesImages;
use Laravel\Ai\Providers\Concerns\HasImageGateway;
use Laravel\Ai\Providers\Provider;

/**
 * laravel/ai image provider for Pixelforge, registered as the "pixelforge" driver.
 *
 * Usage: Image::of('A lighthouse at dawn')->landscape()->generate(provider: 'pixelforge').
 */
final class PixelforgeProvider extends Provider implements ImageProvider
{
    use GeneratesImages;
    use HasImageGateway;

    /**
     * @param  array<array-key, mixed>  $config
     */
    public function __construct(
        protected array $config,
        protected Dispatcher $events,
        private readonly PixelforgeClient $client,
    ) {}

    public function imageGateway(): ImageGateway
    {
        $config = new Fluent($this->config);

        return $this->imageGateway ??= new PixelforgeImageGateway(
            $this->client,
            pollIntervalSeconds: $config->integer('poll_interval', 3),
            defaultTimeoutSeconds: $config->integer('timeout', 180),
        );
    }

    /**
     * Pixelforge has a single model; this label is reported in responses but never sent to the API.
     */
    public function defaultImageModel(): string
    {
        return 'pixelforge';
    }

    /**
     * @param  'low'|'medium'|'high'|null  $quality
     * @return array{size: string, quality: string}
     */
    public function defaultImageOptions(?string $size = null, ?string $quality = null): array
    {
        return [
            'size' => ImageSize::fromSizeOrAspectRatio($size)->value,
            'quality' => ImageQuality::from($quality ?? ImageQuality::Medium->value)->value,
        ];
    }
}
