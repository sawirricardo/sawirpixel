<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Services\Pixelforge\Data\Pricing;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Pixelforge pricing, cached so that estimates do not call the API on every keystroke.
 */
final readonly class PricingCatalog
{
    public const string CACHE_KEY = 'pixelforge.pricing';

    public function __construct(
        private PixelforgeClient $client,
        private Cache $cache,
        private int $ttlSeconds = 3600,
    ) {}

    public function current(): Pricing
    {
        // The raw array is cached because the cache store refuses to unserialize objects.
        $attributes = $this->cache->remember(
            self::CACHE_KEY,
            $this->ttlSeconds,
            fn (): array => $this->client->pricing()->attributes,
        );

        return Pricing::fromArray($attributes);
    }
}
