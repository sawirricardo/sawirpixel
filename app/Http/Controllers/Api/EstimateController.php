<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Requests\EstimateGenerationRequest;
use App\Models\User;
use App\Services\Api\ApiOrganization;
use App\Services\Generations\GenerationLimiter;
use App\Services\Pricing\GenerationCostEstimator;
use Brick\Math\BigDecimal;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class EstimateController
{
    /**
     * Estimate a price.
     *
     * The range a generation could cost, whether the organization's credits cover the most it could cost,
     * and, if its limits would refuse it, why. Requires `generations:read`.
     */
    public function __invoke(EstimateGenerationRequest $request, #[CurrentUser] User $user, GenerationCostEstimator $estimator, GenerationLimiter $limiter): JsonResponse
    {
        $organization = ApiOrganization::for($user);

        $estimate = $estimator->estimate(
            $request->prompt(),
            $request->size(),
            $request->quality(),
            // A follow-up's parent image is sent as one more reference.
            count($request->referenceImages()) + ($request->parentId() === null ? 0 : 1),
        );

        return response()->json(['data' => [
            ...$estimate->toCustomerArray(),
            'affordable' => ! BigDecimal::of($organization->credit_balance)->isLessThan($estimate->chargeMax),
            'blocked' => $limiter->blockedReason($user, $organization, $request->size(), $request->quality(), $estimate->chargeMax),
        ]]);
    }
}
