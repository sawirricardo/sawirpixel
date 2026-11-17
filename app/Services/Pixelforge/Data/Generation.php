<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Data;

use App\Enums\Pixelforge\GenerationStatus;
use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;
use Carbon\CarbonInterface;
use Illuminate\Support\Fluent;

final readonly class Generation
{
    /**
     * @param  array<array-key, mixed>  $attributes  The raw generation object as returned by Pixelforge.
     */
    public function __construct(
        public string $id,
        public GenerationStatus $status,
        public ImageSize $size,
        public ImageQuality $quality,
        public ?string $imageUrl,
        public ?CarbonInterface $imageUrlExpiresAt,
        public ?GenerationUsage $usage,
        public ?GenerationRates $rates,
        public ?GenerationError $error,
        public ?CarbonInterface $createdAt,
        public ?CarbonInterface $completedAt,
        public array $attributes,
    ) {}

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $data = new Fluent($attributes);

        return new self(
            id: $data->string('id')->value(),
            status: GenerationStatus::from($data->string('status')->value()),
            size: ImageSize::from($data->string('size')->value()),
            quality: ImageQuality::from($data->string('quality')->value()),
            imageUrl: $data->filled('image_url') ? $data->string('image_url')->value() : null,
            imageUrlExpiresAt: $data->date('image_url_expires_at'),
            usage: $data->filled('usage') ? GenerationUsage::fromFluent(new Fluent($data->array('usage'))) : null,
            rates: $data->filled('rates') ? GenerationRates::fromFluent(new Fluent($data->array('rates'))) : null,
            error: $data->filled('error') ? new GenerationError(
                $data->string('error.code')->value(),
                $data->string('error.message')->value(),
            ) : null,
            createdAt: $data->date('created_at'),
            completedAt: $data->date('completed_at'),
            attributes: $attributes,
        );
    }
}
