<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\Api\ApiOrganization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * The user behind an API request, with the organization it acts in and what the token may do.
 *
 * @property User $resource
 */
final class UserResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'users';
    }

    public function toId(Request $request): string
    {
        return (string) $this->resource->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        $user = $this->resource;
        $organization = ApiOrganization::for($user);
        $token = $user->currentAccessToken();

        return [
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(),
            'organization' => ['id' => $organization->id, 'name' => $organization->name],
            'token' => $token instanceof PersonalAccessToken ? [
                'name' => $token->name,
                'abilities' => $token->abilities,
                'expires_at' => $token->expires_at?->toIso8601String(),
            ] : null,
        ];
    }
}
