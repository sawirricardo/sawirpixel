<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\GenerationStatus;
use App\Http\Requests\StoreGenerationRequest;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Notifications\GenerationFinished;
use App\Services\Billing\CreditLedger;
use App\Services\Generations\GenerationLimiter;
use App\Services\Generations\GenerationThread;
use App\Services\Pricing\GenerationCostEstimator;
use App\Services\Pricing\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
     * A generation on its thread page: the path from the thread's first prompt to this version, with
     * each version's branches. Only the user who made it can see it, and only while working in its
     * organization; anything else is reported as missing.
     */
    public function show(#[CurrentUser] User $user, string $generation): Response
    {
        $generation = Generation::query()
            ->whereBelongsTo($user)
            ->where('organization_id', $user->current_organization_id)
            ->findOrFail($generation);

        // Seeing the result is reading its "your image is ready" notification.
        $user->unreadNotifications()
            ->where('data->kind', GenerationFinished::KIND)
            ->where('data->generation_id', $generation->id)
            ->update(['read_at' => now()]);

        $thread = new GenerationThread(
            Generation::query()
                ->whereBelongsTo($user)
                ->where('thread_id', $generation->thread_id)
                ->oldest('id')
                ->get(),
        );

        return Inertia::render('generations/Show', [
            'generation' => $this->present($generation),
            'thread' => array_map(fn (Generation $version): array => [
                ...$this->present($version),
                'parent_id' => $version->parent_id,
                'can_continue' => $version->canBeContinued(),
                'branches' => $thread->branchesAt($version),
            ], $thread->pathTo($generation)),
            'latestFollowUpId' => $thread->tipOf($generation)->id === $generation->id ? null : $thread->tipOf($generation)->id,
            'versions' => $this->versionsMap($thread, $generation),
            'branchCount' => $thread->branchCount(),
        ]);
    }

    public function store(StoreGenerationRequest $request, #[CurrentUser] User $user, GenerationCostEstimator $estimator, CreditLedger $ledger, GenerationLimiter $limiter): RedirectResponse
    {
        $organization = $user->currentOrganization;

        if ($organization === null) {
            return to_route('organizations.create');
        }

        Gate::authorize('create', [Generation::class, $organization]);

        $parent = $this->continuableParent($request->parentId(), $user);

        // A follow-up sends the version it continues from as its first reference image.
        $referenceImages = $parent instanceof Generation && $parent->image_url !== null
            ? [$parent->image_url, ...$request->referenceImages()]
            : $request->referenceImages();

        $estimate = $estimator->estimate(
            $request->prompt(),
            $request->size(),
            $request->quality(),
            count($referenceImages),
        );

        // Reserve the most it can cost. The estimate is worked out first: it may fetch pricing over HTTP,
        // which shouldn't happen while the user's row is locked.
        $generation = DB::transaction(function () use ($request, $user, $organization, $estimate, $ledger, $limiter, $parent, $referenceImages): Generation {
            $lockedOrganization = $ledger->lockOrganization($organization->id);

            // Checked under the lock, so concurrent submissions can't both squeeze under a limit.
            $blockedReason = $limiter->blockedReason($user, $lockedOrganization, $request->size(), $request->quality(), $estimate->chargeMax);

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
                'prompt' => $request->prompt(),
                'size' => $request->size(),
                'quality' => $request->quality(),
                'reference_images' => $referenceImages,
                'parent_id' => $parent?->id,
                'thread_id' => $parent?->thread_id,
                'estimated_charge_min' => (string) $estimate->chargeMin,
                'estimated_charge_max' => (string) $estimate->chargeMax,
            ]);

            if ($generation->thread_id === null) {
                $generation->update(['thread_id' => $generation->id]);
            }

            $ledger->hold($lockedOrganization, $generation, $estimate->chargeMax);

            return $generation;
        });

        dispatch(new SubmitGeneration($generation));

        Inertia::flash('success', 'Your image is on its way. We set aside its maximum cost and charge only what it actually costs, or nothing if it fails.');

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
            // A follow-up's first reference is its parent's image, which the thread already shows; list only the ones they added.
            'reference_images' => $generation->parent_id === null ? $generation->reference_images : array_slice($generation->reference_images, 1),
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

    /**
     * The version a follow-up continues from: the user's own, in their current organization, finished,
     * and with an image link that hasn't expired yet.
     *
     * @throws ValidationException
     */
    private function continuableParent(?int $parentId, User $user): ?Generation
    {
        if ($parentId === null) {
            return null;
        }

        $parent = Generation::query()
            ->whereBelongsTo($user)
            ->where('organization_id', $user->current_organization_id)
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

    /**
     * The thread's map: every version, indented by branch, marking the path to the one being viewed.
     *
     * @return list<array{id: int, number: int, depth: int, prompt: string, status: string, image_url: string|null, on_path: bool, is_current: bool}>
     */
    private function versionsMap(GenerationThread $thread, Generation $current): array
    {
        $onPath = array_map(fn (Generation $version): int => $version->id, $thread->pathTo($current));
        $number = 0;

        return array_map(function (array $entry) use ($onPath, $current, &$number): array {
            $version = $entry['generation'];
            $number++;

            return [
                'id' => $version->id,
                'number' => $number,
                'depth' => $entry['depth'],
                'prompt' => Str::limit($version->prompt, 60),
                'status' => $version->status->value,
                'image_url' => $version->canBeContinued() ? $version->image_url : null,
                'on_path' => in_array($version->id, $onPath, true),
                'is_current' => $version->id === $current->id,
            ];
        }, $thread->outline());
    }
}
