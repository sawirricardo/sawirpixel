<?php

declare(strict_types=1);

namespace App\Services\Api;

use App\Models\Organization;
use App\Models\OrgUser;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The organization an API request acts in: the one its personal access token was issued for, or the
 * current organization for a logged-in browser session. Membership is checked on every request, since
 * it can be taken away after a token was issued.
 */
final class ApiOrganization
{
    /**
     * @throws AccessDeniedHttpException
     */
    public static function for(User $user): Organization
    {
        $token = $user->currentAccessToken();
        $organizationId = $token instanceof PersonalAccessToken ? $token->organization_id : $user->current_organization_id;
        $organization = $organizationId === null ? null : Organization::query()->find($organizationId);

        throw_if(! $organization instanceof Organization || ! $user->membershipIn($organization) instanceof OrgUser, AccessDeniedHttpException::class, "This token's organization no longer exists, or you're no longer a member of it.");

        return $organization;
    }
}
