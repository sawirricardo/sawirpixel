<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Data;

use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;

final readonly class GenerationRequest
{
    /**
     * @param  list<string>  $referenceImages  Public http(s) image URLs, at most four.
     * @param  array<string, string>  $metadata
     */
    public function __construct(
        public string $prompt,
        public ImageSize $size = ImageSize::Square,
        public ImageQuality $quality = ImageQuality::Medium,
        public array $referenceImages = [],
        public array $metadata = [],
    ) {}

    /**
     * The request body. Pixelforge rejects unknown fields, so only documented ones are sent.
     *
     * @return array{prompt: string, size: string, quality: string, reference_images?: list<string>, metadata?: array<string, string>}
     */
    public function toArray(): array
    {
        $body = [
            'prompt' => $this->prompt,
            'size' => $this->size->value,
            'quality' => $this->quality->value,
        ];

        if ($this->referenceImages !== []) {
            $body['reference_images'] = $this->referenceImages;
        }

        if ($this->metadata !== []) {
            $body['metadata'] = $this->metadata;
        }

        return $body;
    }
}
