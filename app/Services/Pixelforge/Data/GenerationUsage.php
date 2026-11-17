<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Data;

use Illuminate\Support\Fluent;

final readonly class GenerationUsage
{
    public function __construct(
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public int $totalTokens,
    ) {}

    /**
     * @param  Fluent<string, mixed>  $usage
     */
    public static function fromFluent(Fluent $usage): self
    {
        return new self(
            inputTextTokens: $usage->integer('input_text_tokens'),
            inputImageTokens: $usage->integer('input_image_tokens'),
            outputTokens: $usage->integer('output_tokens'),
            reasoningTokens: $usage->integer('reasoning_tokens'),
            totalTokens: $usage->integer('total_tokens'),
        );
    }
}
