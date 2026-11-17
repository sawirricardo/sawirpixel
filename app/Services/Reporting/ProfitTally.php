<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Services\Pricing\Money;
use Brick\Math\BigDecimal;

/**
 * A total of billed generations: how many, what users were charged and what Pixelforge charged us.
 */
final class ProfitTally
{
    public int $generations = 0;

    public BigDecimal $charged;

    public BigDecimal $providerCost;

    public function __construct()
    {
        $this->charged = BigDecimal::zero();
        $this->providerCost = BigDecimal::zero();
    }

    /**
     * A tally from a row of database aggregates: count(*), sum(charge) and sum(provider_cost).
     */
    public static function fromSums(mixed $generations, mixed $charged, mixed $providerCost): self
    {
        $tally = new self;
        $tally->generations = is_numeric($generations) ? (int) $generations : 0;
        $tally->charged = Money::ofSum($charged);
        $tally->providerCost = Money::ofSum($providerCost);

        return $tally;
    }

    public function profit(): BigDecimal
    {
        return $this->charged->minus($this->providerCost);
    }

    /**
     * @return array{generations: int, charged: array{amount: string, formatted: string}, provider_cost: array{amount: string, formatted: string}, profit: array{amount: string, formatted: string}}
     */
    public function toArray(): array
    {
        return [
            'generations' => $this->generations,
            'charged' => Money::toArray($this->charged),
            'provider_cost' => ProviderCost::toArray($this->providerCost),
            'profit' => Money::toArray($this->profit()),
        ];
    }
}
