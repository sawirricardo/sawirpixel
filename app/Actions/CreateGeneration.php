<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\GenerationStatus;
use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Services\Generations\GenerationLimiter;
use App\Services\Pricing\GenerationCostEstimator;
use App\Services\Pricing\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Starts a generation for a user in an organization: prices it, checks the organization's limits and
 * credits, holds the most it can cost, and queues it for Pixelforge. The one way generations are created,
 * from the web app and the API alike. Callers authorize first (the user may generate in the organization).
 */
final readonly class CreateGeneration
{
    public function __construct(
        private GenerationCostEstimator $estimator,
        private CreditLedger $ledger,
        private GenerationLimiter $limiter,
    ) {}

    /**
     * @param  list<string>  $referenceImages
     *
     * @throws ValidationException when the parent can't be continued, a limit refuses it, or credits don't cover it
     */
    public function handle(User $user, Organization $organization, string $prompt, ImageSize $size, ImageQuality $quality, array $referenceImages = [], ?int $parentId = null): Generation
    {
        $parent = $this->continuableParent($parentId, $user, $organization);

        // A follow-up sends the version it continues from as its first reference image.
        $referenceImages = $parent instanceof Generation && $parent->image_url !== null
            ? [$parent->image_url, ...$referenceImages]
            : $referenceImages;

        $estimate = $this->estimator->estimate($prompt, $size, $quality, count($referenceImages));

        // Reserve the most it can cost. The estimate is worked out first: it may fetch pricing over HTTP,
        // which shouldn't happen while the organization's row is locked.
        $generation = DB::transaction(function () use ($user, $organization, $prompt, $size, $quality, $estimate, $parent, $referenceImages): Generation {
            $lockedOrganization = $this->ledger->lockOrganization($organization->id);

            // Checked under the lock, so concurrent submissions can't both squeeze under a limit.
            $blockedReason = $this->limiter->blockedReason($user, $lockedOrganization, $size, $quality, $estimate->chargeMax);

            if ($blockedReason !== null) {
                throw ValidationException::withMessages(['limit' => $blockedReason]);
            }

            $balance = BigDecimal::of($lockedOrganization->credit_balance);

            if ($balance->isLessThan($estimate->chargeMax)) {
                throw ValidationException::withMessages(['credits' => sprintf(
                    'This could cost up to %s, but %s has %s in credits. Buy credits to generate it.',
                    Money::format($estimate->chargeMax, RoundingMode::Up),
                    $lockedOrganization->name,
                    Money::format($balance, RoundingMode::Down),
                )]);
            }

            $generation = Generation::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'status' => GenerationStatus::Pending,
                'idempotency_key' => (string) Str::uuid(),
                'prompt' => $prompt,
                'size' => $size,
                'quality' => $quality,
                'reference_images' => $referenceImages,
                'parent_id' => $parent?->id,
                'thread_id' => $parent?->thread_id,
                'estimated_charge_min' => (string) $estimate->chargeMin,
                'estimated_charge_max' => (string) $estimate->chargeMax,
            ]);

            if ($generation->thread_id === null) {
                $generation->update(['thread_id' => $generation->id]);
            }

            $this->ledger->hold($lockedOrganization, $generation, $estimate->chargeMax);

            return $generation;
        });

        dispatch(new SubmitGeneration($generation));

        return $generation;
    }

    /**
     * The version a follow-up continues from: the user's own, in this organization, finished, and with an
     * image link that hasn't expired yet.
     *
     * @throws ValidationException
     */
    private function continuableParent(?int $parentId, User $user, Organization $organization): ?Generation
    {
        if ($parentId === null) {
            return null;
        }

        $parent = Generation::query()
            ->whereBelongsTo($user)
            ->whereBelongsTo($organization)
            ->find($parentId);

        if (! $parent instanceof Generation) {
            throw ValidationException::withMessages(['parent_id' => 'That version could not be found.']);
        }

        if (! $parent->canBeContinued()) {
            throw ValidationException::withMessages(['parent_id' => $parent->status === GenerationStatus::Succeeded
                ? "That version's image link has expired, so it can't be continued. Start a new image instead."
                : 'You can only continue from a finished image.']);
        }

        return $parent;
    }
}
