<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\CreateGeneration;
use App\Http\Requests\StoreGenerationRequest;
use App\Models\Generation;
use App\Models\User;
use App\Notifications\GenerationFinished;
use App\Services\Generations\GenerationThread;
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

    public function store(StoreGenerationRequest $request, #[CurrentUser] User $user, CreateGeneration $createGeneration): RedirectResponse
    {
        $organization = $user->currentOrganization;

        if ($organization === null) {
            return to_route('organizations.create');
        }

        Gate::authorize('create', [Generation::class, $organization]);

        $generation = $createGeneration->handle(
            $user,
            $organization,
            $request->prompt(),
            $request->size(),
            $request->quality(),
            $request->referenceImages(),
            $request->parentId(),
        );

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
