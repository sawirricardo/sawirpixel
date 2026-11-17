<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\CreateGeneration;
use App\Http\Requests\StoreGenerationRequest;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use App\Models\User;
use App\Services\Api\ApiOrganization;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class GenerationController
{
    public const int PER_PAGE = 20;

    /**
     * List your generations.
     *
     * Newest first, in the token's organization. Requires `generations:read`.
     */
    public function index(#[CurrentUser] User $user): AnonymousResourceCollection
    {
        return GenerationResource::collection(
            Generation::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo(ApiOrganization::for($user))
                ->latest('id')
                ->paginate(self::PER_PAGE),
        );
    }

    /**
     * Show a generation.
     *
     * Poll this until `status` is `succeeded` or `failed`. `image_url` is valid until `image_url_expires_at`.
     * Requires `generations:read`.
     */
    public function show(#[CurrentUser] User $user, string $generation): GenerationResource
    {
        return new GenerationResource(
            Generation::query()
                ->whereBelongsTo($user)
                ->whereBelongsTo(ApiOrganization::for($user))
                ->findOrFail($generation),
        );
    }

    /**
     * Generate an image.
     *
     * Holds the most it can cost from the organization's credits and returns `202 Accepted` with the new
     * generation. You're charged what it actually costs once it finishes, or nothing if it fails. Pass
     * `parent_id` to continue from one of your finished images. Requires `generations:create` and a
     * verified email address.
     */
    public function store(StoreGenerationRequest $request, #[CurrentUser] User $user, CreateGeneration $createGeneration): JsonResponse
    {
        $organization = ApiOrganization::for($user);

        Gate::forUser($user)->authorize('create', [Generation::class, $organization]);

        throw_unless($user->hasVerifiedEmail(), AccessDeniedHttpException::class, 'Verify your email address before generating images.');

        $generation = $createGeneration->handle(
            $user,
            $organization,
            $request->prompt(),
            $request->size(),
            $request->quality(),
            $request->referenceImages(),
            $request->parentId(),
        );

        return (new GenerationResource($generation))
            ->response()
            ->setStatusCode(202)
            ->header('Location', route('api.generations.show', $generation));
    }
}
