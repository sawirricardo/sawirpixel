<?php

declare(strict_types=1);

use App\Models\User;

test('creates the account, logs it in and asks for an organization', function (): void {
    $response = $this->post(route('register'), [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertRedirect(route('organizations.create'));

    $user = User::query()->where('email', 'ada@example.com')->sole();
    expect($user->name)->toBe('Ada Lovelace');
    $this->assertAuthenticatedAs($user);
});

test('rejects an email that is already registered', function (): void {
    User::factory()->create(['email' => 'ada@example.com']);

    $response = $this->post(route('register'), [
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertSessionHasErrors(['email' => 'The email has already been taken.']);
    $this->assertGuest();
});

test('rejects a password confirmation that does not match', function (): void {
    $response = $this->post(route('register'), [
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'different',
    ]);

    $response->assertSessionHasErrors(['password' => 'The password field confirmation does not match.']);
    $this->assertGuest();
});

test('renders the registration page for a guest', function (): void {
    $response = $this->get(route('register'));

    $response->assertOk()->assertInertia(fn ($page) => $page->component('auth/Register'));
});
