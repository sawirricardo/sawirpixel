<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ManageMembership;
use App\Http\Requests\UpdateMemberRequest;
use App\Models\OrgUser;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

final class MemberController
{
    public function update(UpdateMemberRequest $request, #[CurrentUser] User $user, OrgUser $member, ManageMembership $membership): RedirectResponse
    {
        $this->ensureInCurrentOrganization($user, $member);
        Gate::authorize('manageRoles', $member->organization);

        $membership->changeRoles($user, $member, $request->roles());

        return back();
    }

    public function destroy(#[CurrentUser] User $user, OrgUser $member, ManageMembership $membership): RedirectResponse
    {
        $this->ensureInCurrentOrganization($user, $member);
        Gate::authorize('removeMembers', $member->organization);

        $membership->remove($user, $member);

        return back();
    }

    /**
     * Members of other organizations are reported as missing rather than forbidden.
     */
    private function ensureInCurrentOrganization(User $user, OrgUser $member): void
    {
        abort_unless($member->organization_id === $user->current_organization_id, 404);
    }
}
