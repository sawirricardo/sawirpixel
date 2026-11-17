<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Data;

use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;
use Illuminate\Support\Fluent;
use UnexpectedValueException;

/**
 * Pixelforge's published rates and token tables from GET /v1/pricing.
 */
final readonly class Pricing
{
    /**
     * @param  array<array-key, mixed>  $attributes  The raw pricing payload, kept for caching.
     */
    public function __construct(
        public GenerationRates $rates,
        public int $charactersPerTextToken,
        public string $textTokenVariance,
        public int $tokensPerReferenceImage,
        public array $attributes,
    ) {}

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $data = new Fluent($attributes);

        return new self(
            rates: GenerationRates::fromFluent(new Fluent($data->array('rates'))),
            charactersPerTextToken: $data->integer('tokens.characters_per_text_token', 4),
            textTokenVariance: $data->string('tokens.text_token_variance', '0')->value(),
            tokensPerReferenceImage: $data->integer('tokens.per_reference_image'),
            attributes: $attributes,
        );
    }

    /**
     * Output tokens are fixed per quality and size.
     *
     * @throws UnexpectedValueException when Pixelforge does not publish the combination
     */
    public function outputTokens(ImageQuality $quality, ImageSize $size): int
    {
        return $this->integer(sprintf('tokens.output.%s.%s', $quality->value, $size->value));
    }

    /**
     * Reasoning tokens vary per generation; Pixelforge publishes the range per quality.
     *
     * @return array{min: int, max: int}
     *
     * @throws UnexpectedValueException when Pixelforge does not publish the quality
     */
    public function reasoningTokens(ImageQuality $quality): array
    {
        return [
            'min' => $this->integer(sprintf('tokens.reasoning.%s.min', $quality->value)),
            'max' => $this->integer(sprintf('tokens.reasoning.%s.max', $quality->value)),
        ];
    }

    private function integer(string $key): int
    {
        $data = new Fluent($this->attributes);

        if (! is_numeric($data->get($key))) {
            throw new UnexpectedValueException(sprintf('Pixelforge pricing is missing [%s].', $key));
        }

        return $data->integer($key);
    }
}
