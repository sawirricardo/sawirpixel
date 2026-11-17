<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

test('shows the user their profile', function (): void {
    $user = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);

    $this->actingAs($user)->get(route('profile.edit'))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('settings/Profile')
        ->where('profile', ['name' => 'Ada', 'email' => 'ada@example.com', 'email_verified' => true]));
});

test('updates the name and keeps the email verified when it does not change', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => 'Ada Lovelace', 'email' => 'ada@example.com'])->assertSessionHasNoErrors();
    Notification::assertNothingSent();

    $user->refresh();
    expect($user->name)->toBe('Ada Lovelace');
    expect($user->email_verified_at)->not->toBeNull();
});

test('marks a changed email as unverified and sends a link to the new address', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => 'ada@new.example'])->assertSessionHasNoErrors();

    $user->refresh();
    expect($user->email)->toBe('ada@new.example');
    expect($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
});

test('rejects an email that belongs to someone else', function (): void {
    User::factory()->create(['email' => 'grace@example.com']);
    $user = User::factory()->create(['email' => 'ada@example.com']);

    $this->actingAs($user)->patch(route('profile.update'), ['name' => $user->name, 'email' => 'grace@example.com'])
        ->assertSessionHasErrors(['email' => 'The email has already been taken.']);

    expect($user->refresh()->email)->toBe('ada@example.com');
});

test('changes the password when the current one is right', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('password.update'), [
        'current_password' => 'password',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasNoErrors();

    expect(Hash::check('new-password', $user->refresh()->password))->toBeTrue();
});

test('refuses to change the password without the current one', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('password.update'), [
        'current_password' => 'wrong',
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasErrors(['current_password' => 'The password is incorrect.']);

    expect(Hash::check('password', $user->refresh()->password))->toBeTrue();
});

test('sends guests to log in', function (): void {
    $this->get(route('profile.edit'))->assertRedirect(route('login'));
});
