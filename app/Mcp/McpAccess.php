<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Enums\TokenAbility;
use App\Models\Organization;
use App\Models\User;
use App\Services\Api\ApiOrganization;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The same access rules as the API: a personal access token with the scope the tool needs, acting in
 * the organization the token was issued for, of which the user must still be a member.
 */
final class McpAccess
{
    /**
     * @return array{User, Organization}|Response The user and organization, or an error to return.
     */
    public static function check(Request $request, TokenAbility $ability): array|Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return Response::error('Authenticate with a personal access token: Authorization: Bearer <token>.');
        }

        if (! $user->tokenCan($ability->value)) {
            return Response::error(sprintf('This token is missing the %s scope. Add it to the token in your profile.', $ability->value));
        }

        try {
            return [$user, ApiOrganization::for($user)];
        } catch (AccessDeniedHttpException $accessDeniedHttpException) {
            return Response::error($accessDeniedHttpException->getMessage());
        }
    }
}
