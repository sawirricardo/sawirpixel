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
     * What a customer sees: only the price range. Provider costs and token counts are internal (they'd
     * reveal the supplier's pricing and our margin).
     *
     * @return array{charge: array{min: array{amount: string, formatted: string}, max: array{amount: string, formatted: string}}}
     */
    public function toCustomerArray(): array
    {
        return [
            'charge' => ['min' => Money::toArray($this->chargeMin), 'max' => Money::toArray($this->chargeMax)],
        ];
    }
}
