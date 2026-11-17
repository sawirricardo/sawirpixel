<?php

declare(strict_types=1);

use App\Services\Pricing\Money;

test('formats an exact amount for display', function (string $amount, string $formatted): void {
    expect(Money::format($amount))->toBe($formatted);
})->with([
    'rounds down below half a cent' => ['0.3818875000', '$0.38'],
    'rounds half a cent up' => ['0.0050000000', '$0.01'],
    'rounds up above half a cent' => ['0.0581750000', '$0.06'],
    'shows sub-cent charges instead of $0.00' => ['0.0014000000', '$0.0014'],
    'zero' => ['0.0000000000', '$0.00'],
    'whole dollars' => ['12.5000000000', '$12.50'],
]);

test('pairs the exact amount with its display form', function (): void {
    expect(Money::toArray('0.3818875000'))->toBe(['amount' => '0.3818875000', 'formatted' => '$0.38']);
});
