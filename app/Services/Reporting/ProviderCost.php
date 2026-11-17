<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Brick\Math\BigDecimal;

/**
 * Provider costs are shown to nine decimal places, exactly as Pixelforge reports them, so they can be
 * compared by eye.
 */
final class ProviderCost
{
    public const int SCALE = 9;

    /**
     * @return array{amount: string, formatted: string}
     */
    public static function toArray(BigDecimal|string $amount): array
    {
        $exact = BigDecimal::of($amount)->toScale(self::SCALE);

        return [
            'amount' => (string) $exact,
            'formatted' => ($exact->isNegative() ? '-$' : '$').$exact->abs(),
        ];
    }
}
