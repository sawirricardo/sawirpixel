<?php

declare(strict_types=1);

use App\Enums\DefaultRole;
use Inertia\Testing\AssertableInertia as Assert;

test('shows the organization settings with its members on one page', function (): void {
    $owner = organizationOwner();

    $response = $this->actingAs($owner)->get(route('organization.settings'));

    $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('organizations/Settings')
        ->where('organization.name', 'Acme Studio')
        ->where('canUpdate', true)
        ->has('members', 1));
});

test('renames the organization', function (): void {
    $owner = organizationOwner();

    $response = $this->actingAs($owner)->patch(route('organization.update'), ['name' => 'Acme Labs']);

    $response->assertRedirect()->assertSessionHasNoErrors();
    expect($owner->currentOrganization->refresh()->name)->toBe('Acme Labs');
});

test('requires a name', function (): void {
    $owner = organizationOwner();

    $this->actingAs($owner)->patch(route('organization.update'), ['name' => ''])
        ->assertSessionHasErrors(['name' => 'The name field is required.']);

    expect($owner->currentOrganization->refresh()->name)->toBe('Acme Studio');
});

test('lets members see the settings but not rename the organization', function (): void {
    $organization = organizationOwner()->currentOrganization;
    $member = addMember($organization, DefaultRole::Member);

    $this->actingAs($member)->get(route('organization.settings'))->assertInertia(fn (Assert $page): Assert => $page->where('canUpdate', false));
    $this->actingAs($member)->patch(route('organization.update'), ['name' => 'Hijacked'])->assertForbidden();

    expect($organization->refresh()->name)->toBe('Acme Studio');
});

test('keeps the old members address working', function (): void {
    $this->actingAs(organizationOwner())->get('/organization/members')->assertRedirect('/organization/settings');
});
