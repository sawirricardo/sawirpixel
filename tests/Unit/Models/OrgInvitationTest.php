<?php

declare(strict_types=1);

use App\Enums\InvitationStatus;
use App\Models\OrgInvitation;

test('derives its status from when it was accepted and when it expires', function (string $state, InvitationStatus $status): void {
    $invitation = OrgInvitation::factory()->{$state}()->create();

    expect($invitation->status())->toBe($status);
    expect($invitation->isPending())->toBe($status === InvitationStatus::Pending);
})->with([
    'pending' => ['pending', InvitationStatus::Pending],
    'accepted' => ['accepted', InvitationStatus::Accepted],
    'expired' => ['expired', InvitationStatus::Expired],
]);

test('counts as accepted once accepted, even after it would have expired', function (): void {
    $invitation = OrgInvitation::factory()->accepted()->create(['expires_at' => now()->subDay()]);

    expect($invitation->status())->toBe(InvitationStatus::Accepted);
});

test('expires at exactly its expiry time, in PHP and in SQL alike', function (): void {
    $this->freezeTime();
    $invitation = OrgInvitation::factory()->create(['expires_at' => now()->addSecond()]);

    expect($invitation->status())->toBe(InvitationStatus::Pending);
    expect($invitation->organization->pendingInvitations()->count())->toBe(1);

    $this->travel(1)->second();

    expect($invitation->refresh()->status())->toBe(InvitationStatus::Expired);
    expect($invitation->organization->pendingInvitations()->count())->toBe(0);
});

test('explains why only a pending invitation can be accepted', function (): void {
    expect(InvitationStatus::Pending->canBeAccepted())->toBeTrue();
    expect(InvitationStatus::Pending->refusal())->toBeNull();
    expect(InvitationStatus::Accepted->canBeAccepted())->toBeFalse();
    expect(InvitationStatus::Expired->canBeAccepted())->toBeFalse();
});
