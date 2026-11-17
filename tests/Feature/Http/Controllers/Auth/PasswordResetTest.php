<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

test('emails a reset link to an existing account', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $response = $this->post(route('password.email'), ['email' => 'ada@example.com']);

    $response->assertRedirect()->assertSessionHasNoErrors();
    Notification::assertSentTo($user, ResetPassword::class);
});

test('answers the same way for an address without an account', function (): void {
    Notification::fake();

    $response = $this->post(route('password.email'), ['email' => 'nobody@example.com']);

    $response->assertRedirect()->assertSessionHasNoErrors();
    Notification::assertNothingSent();
});

test('resets the password with a valid token', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com']);
    $token = Password::createToken($user);

    $response = $this->post(route('password.store'), [
        'token' => $token,
        'email' => 'ada@example.com',
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ]);

    $response->assertRedirect(route('login'));

    expect(Hash::check('brand-new-password', $user->refresh()->password))->toBeTrue();
});

test('refuses an invalid or used token', function (): void {
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $response = $this->post(route('password.store'), [
        'token' => 'not-a-real-token',
        'email' => 'ada@example.com',
        'password' => 'brand-new-password',
        'password_confirmation' => 'brand-new-password',
    ]);

    $response->assertSessionHasErrors('email');

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

test('renders the forgot and reset pages', function (): void {
    $this->get(route('password.request'))->assertOk()->assertInertia(fn (Assert $page): Assert => $page->component('auth/ForgotPassword'));
    $this->get(route('password.reset', ['token' => 'abc', 'email' => 'ada@example.com']))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('auth/ResetPassword')->where('token', 'abc')->where('email', 'ada@example.com'));
});
