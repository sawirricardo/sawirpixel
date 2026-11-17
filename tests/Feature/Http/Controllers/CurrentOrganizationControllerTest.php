<?php

declare(strict_types=1);

use App\Enums\DefaultRole;
use App\Models\Organization;

test('switches to another organization the user belongs to', function (): void {
    $owner = organizationOwner();
    $other = Organization::factory()->create();
    addMember($other, DefaultRole::Member, $owner);
    $owner->currentOrganization()->associate($owner->orgUsers()->oldest('id')->value('organization_id'))->save();

    $this->actingAs($owner)->put(route('organizations.switch', $other))->assertRedirect(route('home'));

    expect($owner->refresh()->current_organization_id)->toBe($other->id);
});

test('refuses to switch to an organization the user is not in', function (): void {
    $owner = organizationOwner();
    $stranger = Organization::factory()->create();

    $this->actingAs($owner)->put(route('organizations.switch', $stranger))->assertNotFound();

    expect($owner->refresh()->current_organization_id)->not->toBe($stranger->id);
});
