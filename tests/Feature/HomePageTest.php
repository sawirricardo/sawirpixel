<?php

declare(strict_types=1);

use App\Models\OrgUser;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('shows a guest the generate form without permission to submit', function (): void {
    $response = $this->get(route('home'));

    $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('Home')
        ->where('sizes', ['1024x1024', '1536x1024', '1024x1536'])
        ->where('qualities', ['low', 'medium', 'high'])
        ->where('maxReferenceImages', 4)
        ->where('canGenerate', false)
        ->where('auth.user', null));
});

test('does not let a user without an organization submit yet', function (): void {
    $response = $this->actingAs(User::factory()->create())->get(route('home'));

    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('canGenerate', false)
        ->where('auth.organization', null));
});

test('lets an organization owner submit', function (): void {
    $user = organizationOwner();

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('canGenerate', true)
        ->where('auth.user.email', $user->email)
        ->where('auth.user.is_super_admin', false)
        ->where('auth.organization.name', 'Acme Studio'));
});

test('does not let a member without the generate permission submit', function (): void {
    $user = User::factory()->create();
    $membership = OrgUser::factory()->for($user)->create();
    $user->currentOrganization()->associate($membership->organization_id)->save();

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertInertia(fn (Assert $page): Assert => $page->where('canGenerate', false));
});
