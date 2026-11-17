<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;
use App\Services\Pixelforge\Data\GenerationRates;
use App\Services\Pixelforge\Data\GenerationUsage;
use App\Services\Pixelforge\Data\Pricing;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Estimates what a generation will cost the user: Pixelforge's token cost plus the markup.
 *
 * Provider costs keep 9 decimal places, matching Pixelforge's usage report. User charges are stored
 * exactly (10 decimal places) so the markup is exactly 25% on every generation, however cheap.
 */
final readonly class GenerationCostEstimator
{
    public const int CHARGE_SCALE = 10;

    private const int TOKENS_PER_RATE_UNIT = 1_000_000;

    public function __construct(
        private PricingCatalog $catalog,
        private string $markup,
    ) {}

    public function estimate(string $prompt, ImageSize $size, ImageQuality $quality, int $referenceImageCount): CostEstimate
    {
        $pricing = $this->catalog->current();
        $reasoning = $pricing->reasoningTokens($quality);

        $inputText = $this->textTokens($prompt, $pricing);
        $inputImage = TokenRange::exactly($referenceImageCount * $pricing->tokensPerReferenceImage);
        $output = TokenRange::exactly($pricing->outputTokens($quality, $size));
        $reasoningTokens = new TokenRange($reasoning['min'], $reasoning['max']);

        $providerCostMin = $this->providerCost($pricing->rates, $inputText->min, $inputImage->min, $output->min, $reasoningTokens->min);
        $providerCostMax = $this->providerCost($pricing->rates, $inputText->max, $inputImage->max, $output->max, $reasoningTokens->max);

        return new CostEstimate(
            inputTextTokens: $inputText,
            inputImageTokens: $inputImage,
            outputTokens: $output,
            reasoningTokens: $reasoningTokens,
            providerCostMin: $providerCostMin,
            providerCostMax: $providerCostMax,
            chargeMin: $this->charge($providerCostMin),
            chargeMax: $this->charge($providerCostMax),
        );
    }

    /**
     * What Pixelforge actually charged for a finished generation, at the rates in force when it was created.
     */
    public function providerCostFor(GenerationUsage $usage, GenerationRates $rates): BigDecimal
    {
        return $this->providerCost($rates, $usage->inputTextTokens, $usage->inputImageTokens, $usage->outputTokens, $usage->reasoningTokens);
    }

    /**
     * The charge for a provider cost: exactly the cost plus the markup.
     *
     * Pixelforge's rates have two decimal places, so with a 25% markup every charge is exact at 10 decimal
     * places; only beyond that (never, at current rates) is it rounded up. Charges are kept exact and
     * rounded to the cent only for display and when totals are settled.
     */
    public function charge(BigDecimal $providerCost): BigDecimal
    {
        return $providerCost->multipliedBy($this->markup)->toScale(self::CHARGE_SCALE, RoundingMode::Up);
    }

    /**
     * Pixelforge counts about one text token per four characters, within the published variance.
     */
    private function textTokens(string $prompt, Pricing $pricing): TokenRange
    {
        $tokens = (int) ceil(mb_strlen($prompt) / max(1, $pricing->charactersPerTextToken));
        $variance = BigDecimal::of($pricing->textTokenVariance);

        return new TokenRange(
            min: BigDecimal::of($tokens)->multipliedBy(BigDecimal::one()->minus($variance))->toScale(0, RoundingMode::Floor)->toInt(),
            max: BigDecimal::of($tokens)->multipliedBy(BigDecimal::one()->plus($variance))->toScale(0, RoundingMode::Ceiling)->toInt(),
        );
    }

    private function providerCost(GenerationRates $rates, int $inputText, int $inputImage, int $output, int $reasoning): BigDecimal
    {
        return BigDecimal::of($inputText)->multipliedBy($rates->inputTextTokens)
            ->plus(BigDecimal::of($inputImage)->multipliedBy($rates->inputImageTokens))
            ->plus(BigDecimal::of($output)->multipliedBy($rates->outputTokens))
            ->plus(BigDecimal::of($reasoning)->multipliedBy($rates->reasoningTokens))
            ->dividedBy(self::TOKENS_PER_RATE_UNIT, 9, RoundingMode::Up);
    }
}
