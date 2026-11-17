<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EstimateGenerationRequest;
use App\Services\Pricing\GenerationCostEstimator;
use Illuminate\Http\JsonResponse;

final class GenerationEstimateController
{
    public function __invoke(EstimateGenerationRequest $request, GenerationCostEstimator $estimator): JsonResponse
    {
        return response()->json($estimator->estimate(
            $request->prompt(),
            $request->size(),
            $request->quality(),
            count($request->referenceImages()),
        )->toArray());
    }
}
