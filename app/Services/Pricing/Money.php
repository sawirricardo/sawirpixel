<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Formats exact USD amounts for display. Amounts are stored to fractions of a cent and only rounded here.
 */
final class Money
{
    /**
     * Rounds half-up to the cent. A non-zero amount below half a cent is shown to four decimal places
     * instead, so a real charge is never displayed as $0.00.
     */
    public static function format(BigDecimal|string $amount): string
    {
        $amount = BigDecimal::of($amount);
        $cents = $amount->toScale(2, RoundingMode::HalfUp);

        if ($cents->isZero() && ! $amount->isZero()) {
            return '$'.$amount->toScale(4, RoundingMode::HalfUp);
        }

        return '$'.$cents;
    }

    /**
     * @return array{amount: string, formatted: string}
     */
    public static function toArray(BigDecimal|string $amount): array
    {
        return [
            'amount' => (string) BigDecimal::of($amount),
            'formatted' => self::format($amount),
        ];
    }
}
