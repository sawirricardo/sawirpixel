<?php

declare(strict_types=1);

use App\Enums\DefaultRole;
use App\Models\Organization;
use App\Models\OrgInvitation;
use App\Models\OrgUser;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function membershipOf(User $user): OrgUser
{
    return $user->orgUsers()->where('organization_id', $user->current_organization_id)->sole();
}

describe('index', function (): void {
    test('lists members with their roles and pending invitations for an owner', function (): void {
        $owner = organizationOwner();
        $organization = $owner->currentOrganization;
        $member = addMember($organization, DefaultRole::Member);
        OrgInvitation::factory()->for($organization)->create(['email' => 'pending@example.com']);
        OrgInvitation::factory()->for($organization)->expired()->create();

        $response = $this->actingAs($owner)->get(route('members.index'));

        $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('organizations/Members')
            ->has('members', 2)
            ->where('members.0.email', $owner->email)
            ->where('members.0.role.name', 'Owner')
            ->where('members.0.is_you', true)
            ->where('members.1.email', $member->email)
            ->where('members.1.role.name', 'Member')
            ->has('invitations', 1)
            ->where('invitations.0.email', 'pending@example.com')
            ->has('roles', 3)
            ->where('can', ['invite' => true, 'remove' => true, 'manageRoles' => true]));
    });

    test('shows a plain member the list without management controls or invitations', function (): void {
        $organization = organizationOwner()->currentOrganization;
        OrgInvitation::factory()->for($organization)->create();
        $member = addMember($organization, DefaultRole::Member);

        $response = $this->actingAs($member)->get(route('members.index'));

        $response->assertInertia(fn (Assert $page): Assert => $page
            ->has('members', 2)
            ->has('invitations', 0)
            ->where('can', ['invite' => false, 'remove' => false, 'manageRoles' => false]));
    });

    test('asks a user without an organization to create one first', function (): void {
        $this->actingAs(User::factory()->create())->get(route('members.index'))->assertRedirect(route('organizations.create'));
    });

    test('forbids a membership without any role', function (): void {
        $user = User::factory()->create();
        $membership = OrgUser::factory()->for($user)->create();
        $user->currentOrganization()->associate($membership->organization_id)->save();

        $this->actingAs($user)->get(route('members.index'))->assertForbidden();
    });
});

describe('update', function (): void {
    test("lets an owner change a member's role", function (): void {
        $owner = organizationOwner();
        $member = addMember($owner->currentOrganization, DefaultRole::Member);
        $admin = $owner->currentOrganization->roles()->where('name', 'Admin')->sole();

        $this->actingAs($owner)->patch(route('members.update', membershipOf($member)), ['role_id' => $admin->id])->assertSessionHasNoErrors();

        expect(membershipOf($member)->roles()->pluck('name')->all())->toBe(['Admin']);
    });

    test('lets one of two owners step another owner down', function (): void {
        $owner = organizationOwner();
        $coOwner = addMember($owner->currentOrganization, DefaultRole::Owner);
        $memberRole = $owner->currentOrganization->roles()->where('name', 'Member')->sole();

        $this->actingAs($owner)->patch(route('members.update', membershipOf($coOwner)), ['role_id' => $memberRole->id])->assertSessionHasNoErrors();

        expect(membershipOf($coOwner)->hasRole('Owner'))->toBeFalse();
    });

    test('keeps at least one owner', function (): void {
        $owner = organizationOwner();
        $memberRole = $owner->currentOrganization->roles()->where('name', 'Member')->sole();

        $response = $this->actingAs($owner)->patch(route('members.update', membershipOf($owner)), ['role_id' => $memberRole->id]);

        $response->assertSessionHasErrors(['role_id' => 'An organization needs at least one owner.']);

        expect(membershipOf($owner)->hasRole('Owner'))->toBeTrue();
    });

    test('does not let an admin grant or take away the owner role', function (DefaultRole $targetRole, DefaultRole $newRole): void {
        $organization = organizationOwner()->currentOrganization;
        $admin = addMember($organization, DefaultRole::Admin);
        $target = addMember($organization, $targetRole);
        $role = $organization->roles()->where('name', $newRole->value)->sole();

        $response = $this->actingAs($admin)->patch(route('members.update', membershipOf($target)), ['role_id' => $role->id]);

        $response->assertSessionHasErrors(['role_id' => 'Only owners can grant or remove the Owner role.']);

        expect(membershipOf($target)->hasRole($targetRole->value))->toBeTrue();
    })->with([
        'promote a member to owner' => [DefaultRole::Member, DefaultRole::Owner],
        'demote an owner' => [DefaultRole::Owner, DefaultRole::Member],
    ]);

    test('forbids changing roles without the manage roles permission', function (): void {
        $organization = organizationOwner()->currentOrganization;
        $member = addMember($organization, DefaultRole::Member);
        $other = addMember($organization, DefaultRole::Member);

        $this->actingAs($member)->patch(route('members.update', membershipOf($other)), [
            'role_id' => $organization->roles()->where('name', 'Admin')->value('id'),
        ])->assertForbidden();
    });
});

describe('destroy', function (): void {
    test('removes a member and moves them off the organization', function (): void {
        $owner = organizationOwner();
        $member = addMember($owner->currentOrganization, DefaultRole::Member);
        $elsewhere = Organization::factory()->create();
        addMember($elsewhere, DefaultRole::Member, $member);
        $member->currentOrganization()->associate($owner->currentOrganization)->save();
        $membership = membershipOf($member);

        $this->actingAs($owner)->delete(route('members.destroy', $membership))->assertSessionHasNoErrors();

        $this->assertModelMissing($membership);
        expect($member->refresh()->current_organization_id)->toBe($elsewhere->id);
    });

    test('lets an owner remove a co-owner, leaving them with no current organization', function (): void {
        $owner = organizationOwner();
        $coOwner = addMember($owner->currentOrganization, DefaultRole::Owner);

        $this->actingAs($owner)->delete(route('members.destroy', membershipOf($coOwner)))->assertSessionHasNoErrors();

        expect($coOwner->refresh()->current_organization_id)->toBeNull();
        expect($owner->currentOrganization->ownerCount())->toBe(1);
    });

    test('refuses to remove yourself', function (): void {
        $owner = organizationOwner();

        $this->actingAs($owner)->delete(route('members.destroy', membershipOf($owner)))
            ->assertSessionHasErrors(['member' => "You can't remove yourself."]);

        $this->assertModelExists(membershipOf($owner));
    });

    test('does not let an admin remove an owner', function (): void {
        $owner = organizationOwner();
        $admin = addMember($owner->currentOrganization, DefaultRole::Admin);

        $this->actingAs($admin)->delete(route('members.destroy', membershipOf($owner)))
            ->assertSessionHasErrors(['member' => 'Only owners can remove an owner.']);
    });

    test('hides members of other organizations', function (): void {
        $owner = organizationOwner();
        $stranger = addMember(Organization::factory()->create(), DefaultRole::Member);

        $this->actingAs($owner)->delete(route('members.destroy', membershipOf($stranger)))->assertNotFound();

        $this->assertModelExists(membershipOf($stranger));
    });

    test('forbids removing members without the remove permission', function (): void {
        $organization = organizationOwner()->currentOrganization;
        $member = addMember($organization, DefaultRole::Member);
        $other = addMember($organization, DefaultRole::Member);

        $this->actingAs($member)->delete(route('members.destroy', membershipOf($other)))->assertForbidden();
    });
});
