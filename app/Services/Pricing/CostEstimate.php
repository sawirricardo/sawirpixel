<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Brick\Math\BigDecimal;

/**
 * The cost of a generation before it runs. Reasoning and exact text token counts are only known
 * afterwards, so every amount is a range.
 */
final readonly class CostEstimate
{
    public function __construct(
        public TokenRange $inputTextTokens,
        public TokenRange $inputImageTokens,
        public TokenRange $outputTokens,
        public TokenRange $reasoningTokens,
        public BigDecimal $providerCostMin,
        public BigDecimal $providerCostMax,
        public BigDecimal $chargeMin,
        public BigDecimal $chargeMax,
    ) {}

    /**
     * @return array{
     *     charge: array{min: array{amount: string, formatted: string}, max: array{amount: string, formatted: string}},
     *     provider_cost: array{min: string, max: string},
     *     tokens: array<string, array{min: int, max: int}>
     * }
     */
    public function toArray(): array
    {
        return [
            'charge' => ['min' => Money::toArray($this->chargeMin), 'max' => Money::toArray($this->chargeMax)],
            'provider_cost' => ['min' => (string) $this->providerCostMin, 'max' => (string) $this->providerCostMax],
            'tokens' => [
                'input_text' => ['min' => $this->inputTextTokens->min, 'max' => $this->inputTextTokens->max],
                'input_image' => ['min' => $this->inputImageTokens->min, 'max' => $this->inputImageTokens->max],
                'output' => ['min' => $this->outputTokens->min, 'max' => $this->outputTokens->max],
                'reasoning' => ['min' => $this->reasoningTokens->min, 'max' => $this->reasoningTokens->max],
            ],
        ];
    }
}
