<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EstimateGenerationRequest;
use App\Models\Organization;
use App\Models\User;
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
    public function __invoke(EstimateGenerationRequest $request, GenerationCostEstimator $estimator, #[CurrentUser] ?User $user): JsonResponse
    {
        $estimate = $estimator->estimate(
            $request->prompt(),
            $request->size(),
            $request->quality(),
            // A follow-up's parent image is sent as one more reference.
            count($request->referenceImages()) + ($request->parentId() === null ? 0 : 1),
        );

        return response()->json([
            ...$estimate->toArray(),
            'affordable' => $user?->currentOrganization instanceof Organization
                ? ! BigDecimal::of($user->currentOrganization->credit_balance)->isLessThan($estimate->chargeMax)
                : null,
        ]);
    }
}
