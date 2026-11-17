<?php

declare(strict_types=1);

use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Models\OrgInvitation;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('adds the invited user with the invited roles and permissions and switches them to the organization', function (): void {
    $owner = organizationOwner();
    $organization = $owner->currentOrganization;
    $invitation = OrgInvitation::factory()->for($organization)->withRoles([DefaultRole::Admin])->withToken('secret')->create(['email' => 'grace@example.com']);
    $invitation->givePermissionTo(Permission::DeleteOrganization);

    $grace = User::factory()->create(['email' => 'Grace@Example.com']);

    $response = $this->actingAs($grace)->post(route('invitations.accept', 'secret'));

    $response->assertRedirect(route('home'));

    $membership = $grace->refresh()->membershipIn($organization);
    expect($grace->current_organization_id)->toBe($organization->id);
    expect($membership?->roles()->pluck('name')->all())->toBe(['Admin']);
    expect($membership?->hasDirectPermission(Permission::DeleteOrganization))->toBeTrue();
    expect($invitation->refresh()->accepted_at)->not->toBeNull();
});

test('refuses an invitation sent to a different address', function (): void {
    $invitation = OrgInvitation::factory()->withToken('secret')->create(['email' => 'grace@example.com']);
    $someoneElse = User::factory()->create(['email' => 'mallory@example.com']);

    $response = $this->actingAs($someoneElse)->post(route('invitations.accept', 'secret'));

    $response->assertSessionHasErrors(['invitation' => 'This invitation was sent to grace@example.com. Log in with that email to accept it.']);

    expect($someoneElse->membershipIn($invitation->organization))->toBeNull();
    expect($invitation->refresh()->accepted_at)->toBeNull();
});

test('refuses an expired or already used invitation', function (string $state): void {
    $invitation = OrgInvitation::factory()->{$state}()->withToken('secret')->create(['email' => 'grace@example.com']);
    $grace = User::factory()->create(['email' => 'grace@example.com']);

    $response = $this->actingAs($grace)->post(route('invitations.accept', 'secret'));

    $response->assertSessionHasErrors(['invitation' => 'This invitation has expired or was already used.']);

    expect($grace->membershipIn($invitation->organization))->toBeNull();
})->with(['expired', 'accepted']);

test('returns 404 for an unknown token', function (): void {
    $this->actingAs(User::factory()->create())->post(route('invitations.accept', 'nope'))->assertNotFound();
});

test('does not duplicate the membership of someone who already belongs', function (): void {
    $owner = organizationOwner();
    OrgInvitation::factory()->for($owner->currentOrganization)->withToken('secret')->create(['email' => $owner->email]);

    $this->actingAs($owner)->post(route('invitations.accept', 'secret'))->assertRedirect(route('home'));

    expect($owner->orgUsers()->count())->toBe(1);
    expect($owner->membershipIn($owner->currentOrganization)?->hasRole(DefaultRole::Owner->value))->toBeTrue();
});

test('shows a guest the invitation and brings them back to it after logging in', function (): void {
    OrgInvitation::factory()->withToken('secret')->create(['email' => 'grace@example.com']);
    $grace = User::factory()->create(['email' => 'grace@example.com']);

    $this->get(route('invitations.show', 'secret'))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('invitations/Show')
        ->where('invitation.email', 'grace@example.com')
        ->where('invitation.roles', ['Member'])
        ->where('invitation.permissions', [])
        ->where('invitation.is_pending', true)
        ->where('emailMatches', false));
    $login = $this->post(route('login'), ['email' => 'grace@example.com', 'password' => 'password']);

    $login->assertRedirect(route('invitations.show', 'secret'));
    $this->get(route('invitations.show', 'secret'))->assertInertia(fn (Assert $page): Assert => $page->where('emailMatches', true));
});

test('shows an unknown or expired invitation as not valid', function (): void {
    OrgInvitation::factory()->expired()->withToken('old')->create();

    $this->get(route('invitations.show', 'old'))->assertInertia(fn (Assert $page): Assert => $page->where('invitation.is_pending', false));
    $this->get(route('invitations.show', 'unknown'))->assertInertia(fn (Assert $page): Assert => $page->where('invitation', null));
});
