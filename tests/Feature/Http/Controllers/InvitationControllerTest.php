<?php

declare(strict_types=1);

use App\Enums\DefaultRole;
use App\Enums\Permission;
use App\Mail\OrgInvitationMail;
use App\Models\Organization;
use App\Models\OrgInvitation;
use Illuminate\Support\Facades\Mail;

describe('store', function (): void {
    test('invites an email with roles and extra permissions and mails a link whose token is stored only as a hash', function (): void {
        Mail::fake();
        $owner = organizationOwner();
        $organization = $owner->currentOrganization;
        $admin = $organization->roles()->where('name', 'Admin')->sole();
        $member = $organization->roles()->where('name', 'Member')->sole();

        $response = $this->actingAs($owner)->post(route('invitations.store'), [
            'email' => 'New.Person@Example.com',
            'role_ids' => [$admin->id, $member->id],
            'permissions' => ['organization.delete'],
        ]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $invitation = OrgInvitation::query()->sole();
        expect($invitation->email)->toBe('new.person@example.com');
        expect($invitation->roles()->orderBy('roles.id')->pluck('name')->all())->toBe(['Admin', 'Member']);
        expect($invitation->permissions()->pluck('name')->all())->toBe([Permission::DeleteOrganization]);
        expect($invitation->invited_by_id)->toBe($owner->id);
        expect($invitation->expires_at->toDateTimeString())->toBe(now()->addDays(7)->toDateTimeString());
        Mail::assertQueued(OrgInvitationMail::class, fn (OrgInvitationMail $mail): bool => $mail->hasTo('new.person@example.com')
            && OrgInvitation::hashToken($mail->token) === $invitation->token_hash
            && $mail->token !== $invitation->token_hash);
    });

    test('replaces a pending invitation to the same address', function (): void {
        Mail::fake();
        $owner = organizationOwner();
        $old = OrgInvitation::factory()->for($owner->currentOrganization)->create(['email' => 'new@example.com']);

        $this->actingAs($owner)->post(route('invitations.store'), [
            'email' => 'new@example.com',
            'role_ids' => [$owner->currentOrganization->roles()->where('name', 'Member')->value('id')],
        ]);

        $this->assertModelMissing($old);
        expect(OrgInvitation::query()->count())->toBe(1);
        $this->assertDatabaseMissing('roleables', ['roleable_type' => 'org_invitation', 'roleable_id' => $old->id]);
    });

    test('rejects inviting someone who is already a member', function (): void {
        Mail::fake();
        $owner = organizationOwner();

        $response = $this->actingAs($owner)->post(route('invitations.store'), [
            'email' => $owner->email,
            'role_ids' => [$owner->currentOrganization->roles()->where('name', 'Member')->value('id')],
        ]);

        $response->assertSessionHasErrors(['email' => 'That person is already a member.']);
        Mail::assertNothingQueued();
    });

    test('rejects an invalid email address', function (): void {
        Mail::fake();
        $owner = organizationOwner();

        $response = $this->actingAs($owner)->post(route('invitations.store'), [
            'email' => 'not-an-email',
            'role_ids' => [$owner->currentOrganization->roles()->where('name', 'Member')->value('id')],
        ]);

        $response->assertSessionHasErrors(['email' => 'The email field must be a valid email address.']);
        Mail::assertNothingQueued();
    });

    test('rejects a role that belongs to another organization', function (): void {
        Mail::fake();
        $owner = organizationOwner();
        $foreignRole = Organization::factory()->create()->roles()->where('name', 'Member')->sole();

        $response = $this->actingAs($owner)->post(route('invitations.store'), ['email' => 'new@example.com', 'role_ids' => [$foreignRole->id]]);

        $response->assertSessionHasErrors('role_ids.0');
        Mail::assertNothingQueued();
    });

    test('does not let an admin invite someone as an owner', function (): void {
        Mail::fake();
        $organization = organizationOwner()->currentOrganization;
        $admin = addMember($organization, DefaultRole::Admin);

        $response = $this->actingAs($admin)->post(route('invitations.store'), ['email' => 'new@example.com', 'role_ids' => [$organization->ownerRole()->id]]);

        $response->assertSessionHasErrors(['role_ids' => "You can't grant the Owner role."]);
        Mail::assertNothingQueued();
    });

    test('does not let an inviter grant a permission they do not have', function (): void {
        Mail::fake();
        $organization = organizationOwner()->currentOrganization;
        $admin = addMember($organization, DefaultRole::Admin);

        $response = $this->actingAs($admin)->post(route('invitations.store'), [
            'email' => 'new@example.com',
            'role_ids' => [$organization->roles()->where('name', 'Member')->value('id')],
            'permissions' => ['organization.delete'],
        ]);

        $response->assertSessionHasErrors(['permissions' => "You can't grant a permission you don't have: delete the organization."]);
        Mail::assertNothingQueued();
    });

    test('requires at least one role', function (): void {
        Mail::fake();

        $response = $this->actingAs(organizationOwner())->post(route('invitations.store'), ['email' => 'new@example.com', 'role_ids' => []]);

        $response->assertSessionHasErrors(['role_ids' => 'Choose at least one role.']);
        Mail::assertNothingQueued();
    });

    test('forbids members without the invite permission', function (): void {
        Mail::fake();
        $organization = organizationOwner()->currentOrganization;
        $member = addMember($organization, DefaultRole::Member);

        $response = $this->actingAs($member)->post(route('invitations.store'), [
            'email' => 'new@example.com',
            'role_ids' => [$organization->roles()->where('name', 'Member')->value('id')],
        ]);

        $response->assertForbidden();
        Mail::assertNothingQueued();
    });
});

describe('resend and revoke', function (): void {
    test('resends with a new link and a new expiry', function (): void {
        Mail::fake();
        $owner = organizationOwner();
        $invitation = OrgInvitation::factory()->for($owner->currentOrganization)->withToken('old-token')->create(['expires_at' => now()->addDay()]);

        $this->actingAs($owner)->put(route('invitations.resend', $invitation))->assertRedirect();

        $invitation->refresh();
        expect($invitation->token_hash)->not->toBe(OrgInvitation::hashToken('old-token'));
        expect($invitation->expires_at->toDateTimeString())->toBe(now()->addDays(7)->toDateTimeString());
        Mail::assertQueued(OrgInvitationMail::class, fn (OrgInvitationMail $mail): bool => OrgInvitation::hashToken($mail->token) === $invitation->token_hash);
    });

    test('revokes a pending invitation', function (): void {
        $owner = organizationOwner();
        $invitation = OrgInvitation::factory()->for($owner->currentOrganization)->create();

        $this->actingAs($owner)->delete(route('invitations.destroy', $invitation))->assertRedirect();

        $this->assertModelMissing($invitation);
    });

    test("hides another organization's invitations", function (string $method, string $route): void {
        Mail::fake();
        $owner = organizationOwner();
        $invitation = OrgInvitation::factory()->create();

        $this->actingAs($owner)->{$method}(route($route, $invitation))->assertNotFound();

        $this->assertModelExists($invitation);
        Mail::assertNothingQueued();
    })->with([
        'resend' => ['put', 'invitations.resend'],
        'revoke' => ['delete', 'invitations.destroy'],
    ]);
});

test('escapes the organization name in the invitation email', function (): void {
    $organization = Organization::factory()->create(['name' => "O'Reilly <script>alert('x')</script>"]);
    $invitation = OrgInvitation::factory()->for($organization)->create();

    $html = (new OrgInvitationMail($invitation, 'token'))->render();

    expect($html)->toContain('&lt;script&gt;')->not->toContain("<script>alert('x')</script>");
    expect($html)->toContain(route('invitations.show', 'token'));
});
