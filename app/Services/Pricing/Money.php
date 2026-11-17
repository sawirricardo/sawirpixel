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
     * Rounds to the cent (half-up unless told otherwise). A non-zero amount that would show as $0.00 is
     * shown to four decimal places instead, so a real charge is never displayed as free.
     */
    public static function format(BigDecimal|string $amount, RoundingMode $roundingMode = RoundingMode::HalfUp): string
    {
        $amount = BigDecimal::of($amount);
        $cents = $amount->toScale(2, $roundingMode);

        if ($cents->isZero() && ! $amount->isZero()) {
            return ($amount->isNegative() ? '-$' : '$').$amount->abs()->toScale(4, $roundingMode);
        }

        return ($cents->isNegative() ? '-$' : '$').$cents->abs();
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

    /**
     * An amount summed by the database. Postgres sums numeric columns exactly and returns them as
     * strings; an empty set sums to NULL, which is zero.
     */
    public static function ofSum(mixed $sum): BigDecimal
    {
        return is_string($sum) || is_int($sum) ? BigDecimal::of($sum) : BigDecimal::zero();
    }
}
