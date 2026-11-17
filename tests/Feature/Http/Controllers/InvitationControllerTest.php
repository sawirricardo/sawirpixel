<?php

declare(strict_types=1);

use App\Enums\DefaultRole;
use App\Mail\OrgInvitationMail;
use App\Models\Organization;
use App\Models\OrgInvitation;
use Illuminate\Support\Facades\Mail;

describe('store', function (): void {
    test('invites an email with a role and mails a link whose token is stored only as a hash', function (): void {
        Mail::fake();
        $owner = organizationOwner();
        $organization = $owner->currentOrganization;
        $admin = $organization->roles()->where('name', 'Admin')->sole();

        $response = $this->actingAs($owner)->post(route('invitations.store'), ['email' => 'New.Person@Example.com', 'role_id' => $admin->id]);

        $response->assertRedirect()->assertSessionHasNoErrors();
        $invitation = OrgInvitation::query()->sole();
        expect($invitation->email)->toBe('new.person@example.com');
        expect($invitation->role_id)->toBe($admin->id);
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
            'role_id' => $owner->currentOrganization->roles()->where('name', 'Member')->value('id'),
        ]);

        $this->assertModelMissing($old);
        expect(OrgInvitation::query()->count())->toBe(1);
    });

    test('rejects inviting someone who is already a member', function (): void {
        Mail::fake();
        $owner = organizationOwner();

        $response = $this->actingAs($owner)->post(route('invitations.store'), [
            'email' => $owner->email,
            'role_id' => $owner->currentOrganization->roles()->where('name', 'Member')->value('id'),
        ]);

        $response->assertSessionHasErrors(['email' => 'That person is already a member.']);
        Mail::assertNothingQueued();
    });

    test('rejects an invalid email address', function (): void {
        Mail::fake();
        $owner = organizationOwner();

        $response = $this->actingAs($owner)->post(route('invitations.store'), [
            'email' => 'not-an-email',
            'role_id' => $owner->currentOrganization->roles()->where('name', 'Member')->value('id'),
        ]);

        $response->assertSessionHasErrors(['email' => 'The email field must be a valid email address.']);
        Mail::assertNothingQueued();
    });

    test('rejects a role that belongs to another organization', function (): void {
        Mail::fake();
        $owner = organizationOwner();
        $foreignRole = Organization::factory()->create()->roles()->where('name', 'Member')->sole();

        $response = $this->actingAs($owner)->post(route('invitations.store'), ['email' => 'new@example.com', 'role_id' => $foreignRole->id]);

        $response->assertSessionHasErrors('role_id');
        Mail::assertNothingQueued();
    });

    test('does not let an admin invite someone as an owner', function (): void {
        Mail::fake();
        $organization = organizationOwner()->currentOrganization;
        $admin = addMember($organization, DefaultRole::Admin);

        $response = $this->actingAs($admin)->post(route('invitations.store'), ['email' => 'new@example.com', 'role_id' => $organization->ownerRole()->id]);

        $response->assertSessionHasErrors(['role_id' => 'Only owners can invite someone as an owner.']);
        Mail::assertNothingQueued();
    });

    test('forbids members without the invite permission', function (): void {
        Mail::fake();
        $organization = organizationOwner()->currentOrganization;
        $member = addMember($organization, DefaultRole::Member);

        $response = $this->actingAs($member)->post(route('invitations.store'), [
            'email' => 'new@example.com',
            'role_id' => $organization->roles()->where('name', 'Member')->value('id'),
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
