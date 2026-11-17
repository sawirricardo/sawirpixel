<?php

declare(strict_types=1);

use App\Enums\DefaultRole;
use App\Models\User;

test('creates the organization with the user as owner and switches to it', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->post(route('organizations.store'), ['name' => 'Acme Studio']);

    $response->assertRedirect(route('home'));

    $organization = $user->refresh()->currentOrganization;
    expect($organization?->name)->toBe('Acme Studio');
    expect($user->membershipIn($organization)?->hasRole(DefaultRole::Owner->value))->toBeTrue();
});

test('requires an organization name', function (): void {
    $response = $this->actingAs(User::factory()->create())->post(route('organizations.store'), ['name' => '']);

    $response->assertSessionHasErrors(['name' => 'The name field is required.']);
});

test('sends a guest to log in before creating an organization', function (): void {
    $response = $this->get(route('organizations.create'));

    $response->assertRedirect(route('login'));
});

test('renders the create organization page', function (): void {
    $response = $this->actingAs(User::factory()->create())->get(route('organizations.create'));

    $response->assertOk()->assertInertia(fn ($page) => $page->component('organizations/Create'));
});
