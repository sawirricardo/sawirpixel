<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EstimateGenerationRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Generations\GenerationLimiter;
use App\Services\Pricing\GenerationCostEstimator;
use Brick\Math\BigDecimal;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class GenerationEstimateController
{
    /**
     * The estimated charge range and, for a user working in an organization, whether its balance covers the maximum
     * (which is what submitting reserves).
     */
    public function __invoke(EstimateGenerationRequest $request, GenerationCostEstimator $estimator, GenerationLimiter $limiter, #[CurrentUser] ?User $user): JsonResponse
    {
        $estimate = $estimator->estimate(
            $request->prompt(),
            $request->size(),
            $request->quality(),
            // A follow-up's parent image is sent as one more reference.
            count($request->referenceImages()) + ($request->parentId() === null ? 0 : 1),
        );

        // A fresh, full row: the limits aren't part of every partial load of the organization.
        $organization = $user instanceof User && $user->current_organization_id !== null
            ? Organization::query()->find($user->current_organization_id)
            : null;

        return response()->json([
            ...$estimate->toCustomerArray(),
            'affordable' => $organization instanceof Organization
                ? ! BigDecimal::of($organization->credit_balance)->isLessThan($estimate->chargeMax)
                : null,
            // Why submitting would be refused by the organization's limits, if it would.
            'blocked' => $user instanceof User && $organization instanceof Organization
                ? $limiter->blockedReason($user, $organization, $request->size(), $request->quality(), $estimate->chargeMax)
                : null,
        ]);
    }
}
