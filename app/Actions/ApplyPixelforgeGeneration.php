<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Enums\Pixelforge\GenerationStatus as PixelforgeStatus;
use App\Models\Generation;
use App\Services\Billing\CreditLedger;
use App\Services\Generations\FailureMessage;
use App\Services\Pixelforge\Data\Generation as PixelforgeGeneration;
use App\Services\Pixelforge\Data\GenerationRates;
use App\Services\Pixelforge\Data\GenerationUsage;
use App\Services\Pricing\GenerationCostEstimator;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * Records Pixelforge's view of a generation. Polling and webhooks both go through here, so it must be
 * safe to apply the same or an older snapshot any number of times, in any order.
 *
 * Status only moves forward (pending → queued → processing → succeeded|failed) and never leaves a final
 * state, so the cost of a finished generation is recorded exactly once, and the organization's credits are
 * settled (hold returned, actual cost charged) in the same transaction.
 */
final readonly class ApplyPixelforgeGeneration
{
    public function __construct(
        private GenerationCostEstimator $estimator,
        private CreditLedger $ledger,
    ) {}

    public function handle(Generation $generation, PixelforgeGeneration $snapshot): Generation
    {
        return DB::transaction(function () use ($generation, $snapshot): Generation {
            $locked = Generation::query()->whereKey($generation->id)->lockForUpdate()->sole();
            $status = $this->localStatus($snapshot->status);

            $locked->pixelforge_id ??= $snapshot->id;

            if ($this->isFinal($locked->status) || $this->rank($status) <= $this->rank($locked->status)) {
                $locked->save();

                return $locked;
            }

            $locked->status = $status;

            if ($snapshot->status === PixelforgeStatus::Succeeded) {
                $this->recordSuccess($locked, $snapshot);
            }

            if ($snapshot->status === PixelforgeStatus::Failed) {
                $this->recordFailure($locked, $snapshot);
            }

            $locked->save();

            return $locked;
        });
    }

    /**
     * A success is only recorded together with its cost. Without usage and rates the snapshot is refused,
     * so the webhook or poller retries instead of finalising a generation that can never be charged.
     *
     * @throws UnexpectedValueException
     */
    private function recordSuccess(Generation $generation, PixelforgeGeneration $snapshot): void
    {
        if (! $snapshot->usage instanceof GenerationUsage || ! $snapshot->rates instanceof GenerationRates) {
            throw new UnexpectedValueException(sprintf(
                'Pixelforge reported generation [%s] as succeeded without usage or rates.',
                $snapshot->id,
            ));
        }

        $providerCost = $this->estimator->providerCostFor($snapshot->usage, $snapshot->rates);

        $charge = $this->estimator->charge($providerCost);

        $generation->provider_cost = (string) $providerCost;
        $generation->charge = (string) $charge;

        $this->ledger->settle($generation, $charge);
        $generation->usage = [
            'input_text_tokens' => $snapshot->usage->inputTextTokens,
            'input_image_tokens' => $snapshot->usage->inputImageTokens,
            'output_tokens' => $snapshot->usage->outputTokens,
            'reasoning_tokens' => $snapshot->usage->reasoningTokens,
            'total_tokens' => $snapshot->usage->totalTokens,
        ];

        $generation->image_url = $snapshot->imageUrl;
        $generation->image_url_expires_at = $snapshot->imageUrlExpiresAt;
        $generation->completed_at = $snapshot->completedAt ?? now();
    }

    /**
     * Failed generations are not billed by Pixelforge and cost the user nothing.
     */
    private function recordFailure(Generation $generation, PixelforgeGeneration $snapshot): void
    {
        $generation->provider_cost = '0';
        $generation->charge = '0';

        $this->ledger->settle($generation, null);
        $generation->error_code = $snapshot->error?->code;
        $generation->error_message = FailureMessage::for($snapshot->error?->code);
        $generation->completed_at = $snapshot->completedAt ?? now();
    }

    private function localStatus(PixelforgeStatus $status): GenerationStatus
    {
        return match ($status) {
            PixelforgeStatus::Queued => GenerationStatus::Queued,
            PixelforgeStatus::Processing => GenerationStatus::Processing,
            PixelforgeStatus::Succeeded => GenerationStatus::Succeeded,
            PixelforgeStatus::Failed => GenerationStatus::Failed,
        };
    }

    private function isFinal(GenerationStatus $status): bool
    {
        return $status === GenerationStatus::Succeeded || $status === GenerationStatus::Failed;
    }

    private function rank(GenerationStatus $status): int
    {
        return match ($status) {
            GenerationStatus::Pending => 0,
            GenerationStatus::Queued => 1,
            GenerationStatus::Processing => 2,
            GenerationStatus::Succeeded, GenerationStatus::Failed => 3,
        };
    }
}
