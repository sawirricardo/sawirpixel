<?php

declare(strict_types=1);

use App\Http\Requests\LoginRequest;
use App\Models\User;

test('logs the user in and sends them to the page they came for', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $response = $this->withSession(['url.intended' => route('generations.index')])
        ->post(route('login'), ['email' => 'ada@example.com', 'password' => 'password']);

    $response->assertRedirect(route('generations.index'));
    $this->assertAuthenticatedAs($user);
});

test('rejects a wrong password with a validation error', function (): void {
    User::factory()->create(['email' => 'ada@example.com']);

    $response = $this->from(route('login'))->post(route('login'), ['email' => 'ada@example.com', 'password' => 'wrong']);

    $response->assertRedirect(route('login'))->assertSessionHasErrors(['email' => trans('auth.failed')]);
    $this->assertGuest();
});

test('locks out further attempts after five failures', function (): void {
    User::factory()->create(['email' => 'ada@example.com']);

    foreach (range(1, LoginRequest::MAX_ATTEMPTS) as $attempt) {
        $this->post(route('login'), ['email' => 'ada@example.com', 'password' => 'wrong']);
    }

    $response = $this->post(route('login'), ['email' => 'ada@example.com', 'password' => 'password']);

    $response->assertSessionHasErrors('email');

    expect(session('errors')->first('email'))->toContain('Too many login attempts');
    $this->assertGuest();
});

test('logs the user out', function (): void {
    $response = $this->actingAs(User::factory()->create())->post(route('logout'));

    $response->assertRedirect(route('home'));
    $this->assertGuest();
});

test('redirects a logged-in user away from the login page', function (): void {
    $response = $this->actingAs(User::factory()->create())->get(route('login'));

    $response->assertRedirect();
});

test('renders the login page for a guest', function (): void {
    $response = $this->get(route('login'));

    $response->assertOk()->assertInertia(fn ($page) => $page->component('auth/Login'));
});
