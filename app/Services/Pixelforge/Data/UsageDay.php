<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Data;

use Illuminate\Support\Fluent;

/**
 * What Pixelforge billed for one UTC day (or the whole range, for the total).
 */
final readonly class UsageDay
{
    public function __construct(
        public ?string $date,
        public int $generations,
        public int $inputTextTokens,
        public int $inputImageTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public string $costUsd,
    ) {}

    /**
     * @param  Fluent<string, mixed>  $day
     */
    public static function fromFluent(Fluent $day): self
    {
        return new self(
            date: $day->filled('date') ? $day->string('date')->value() : null,
            generations: $day->integer('generations'),
            inputTextTokens: $day->integer('input_text_tokens'),
            inputImageTokens: $day->integer('input_image_tokens'),
            outputTokens: $day->integer('output_tokens'),
            reasoningTokens: $day->integer('reasoning_tokens'),
            costUsd: $day->string('cost_usd', '0')->value(),
        );
    }
}
