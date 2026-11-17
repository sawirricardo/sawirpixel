<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Services\Pricing\GenerationCostEstimator;
use App\Services\Pricing\Money;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class GenerationController
{
    public const int PER_PAGE = 20;

    public function index(#[CurrentUser] User $user): Response|RedirectResponse
    {
        $organization = $user->currentOrganization;

        if ($organization === null) {
            return to_route('organizations.create');
        }

        $generations = Generation::query()
            ->whereBelongsTo($organization)
            ->whereBelongsTo($user)
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->through($this->present(...));

        return Inertia::render('generations/Index', [
            'generations' => $generations,
        ]);
    }

    /**
     * One generation, for its own page. Only the user who made it can see it, and only while working in
     * that generation's organization; anything else is reported as missing.
     */
    public function show(#[CurrentUser] User $user, string $generation): Response
    {
        $generation = Generation::query()
            ->whereBelongsTo($user)
            ->where('organization_id', $user->current_organization_id)
            ->findOrFail($generation);

        return Inertia::render('generations/Show', [
            'generation' => $this->present($generation),
        ]);
    }

    public function store(StoreGenerationRequest $request, #[CurrentUser] User $user, GenerationCostEstimator $estimator): RedirectResponse
    {
        $organization = $user->currentOrganization;

        if ($organization === null) {
            return to_route('organizations.create');
        }

        Gate::authorize('create', [Generation::class, $organization]);

        $estimate = $estimator->estimate(
            $request->prompt(),
            $request->size(),
            $request->quality(),
            count($request->referenceImages()),
        );

        $generation = Generation::query()->create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'status' => GenerationStatus::Pending,
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => $request->prompt(),
            'size' => $request->size(),
            'quality' => $request->quality(),
            'reference_images' => $request->referenceImages(),
            'estimated_charge_min' => (string) $estimate->chargeMin,
            'estimated_charge_max' => (string) $estimate->chargeMax,
        ]);

        dispatch(new SubmitGeneration($generation));

        Inertia::flash('success', 'Your image is on its way. You will only be charged if it succeeds.');

        return to_route('generations.show', $generation);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Generation $generation): array
    {
        return [
            'id' => $generation->id,
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'reference_images' => $generation->reference_images,
            'status' => $generation->status->value,
            'estimated_charge' => ['min' => Money::toArray($generation->estimated_charge_min), 'max' => Money::toArray($generation->estimated_charge_max)],
            'charge' => $generation->charge === null ? null : Money::toArray($generation->charge),
            'image_url' => $generation->image_url,
            'image_url_expires_at' => $generation->image_url_expires_at?->toIso8601String(),
            'error_message' => $generation->error_message,
            'created_at' => $generation->created_at?->toIso8601String(),
            'completed_at' => $generation->completed_at?->toIso8601String(),
        ];
    }
}
