<?php

declare(strict_types=1);

use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;
use App\Services\Pricing\GenerationCostEstimator;
use Brick\Math\BigDecimal;

test('includes reference images, the output size and the reasoning range in the estimate', function (): void {
    fakePixelforgePricing();

    $estimate = resolve(GenerationCostEstimator::class)->estimate(str_repeat('a', 400), ImageSize::Landscape, ImageQuality::High, 2);

    expect($estimate->inputTextTokens->min)->toBe(85);
    expect($estimate->inputTextTokens->max)->toBe(115);
    expect($estimate->inputImageTokens->max)->toBe(1530);
    expect($estimate->outputTokens->max)->toBe(6450);
    expect((string) $estimate->providerCostMin)->toBe('0.286225000');
    expect((string) $estimate->providerCostMax)->toBe('0.373875000');
    expect((string) $estimate->chargeMin)->toBe('0.3577812500');
    expect((string) $estimate->chargeMax)->toBe('0.4673437500');
});

test('estimates no text tokens for an empty prompt', function (): void {
    fakePixelforgePricing();

    $estimate = resolve(GenerationCostEstimator::class)->estimate('', ImageSize::Square, ImageQuality::Low, 0);

    expect($estimate->inputTextTokens->max)->toBe(0);
    expect((string) $estimate->providerCostMin)->toBe('0.011200000');
});

test('charges exactly the provider cost plus 25 percent, without rounding to the cent', function (string $providerCost, string $charge): void {
    $estimator = resolve(GenerationCostEstimator::class);

    expect((string) $estimator->charge(BigDecimal::of($providerCost)))->toBe($charge);
})->with([
    'the cheapest image' => ['0.011200000', '0.0140000000'],
    'a typical high quality image' => ['0.305510000', '0.3818875000'],
    'whole cents' => ['0.080000000', '0.1000000000'],
    'free' => ['0', '0.0000000000'],
]);

test('rounds up only beyond ten decimal places', function (): void {
    $estimator = resolve(GenerationCostEstimator::class);

    expect((string) $estimator->charge(BigDecimal::of('0.000000001')))->toBe('0.0000000013');
});
