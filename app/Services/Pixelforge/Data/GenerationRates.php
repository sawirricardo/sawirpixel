<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Data;

use Illuminate\Support\Fluent;

/**
 * USD per one million tokens, kept as exact decimal strings.
 */
final readonly class GenerationRates
{
    public function __construct(
        public string $inputTextTokens,
        public string $inputImageTokens,
        public string $outputTokens,
        public string $reasoningTokens,
    ) {}

    /**
     * @param  Fluent<string, mixed>  $rates
     */
    public static function fromFluent(Fluent $rates): self
    {
        return new self(
            inputTextTokens: $rates->string('input_text_tokens')->value(),
            inputImageTokens: $rates->string('input_image_tokens')->value(),
            outputTokens: $rates->string('output_tokens')->value(),
            reasoningTokens: $rates->string('reasoning_tokens')->value(),
        );
    }
}
