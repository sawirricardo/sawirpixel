<?php

declare(strict_types=1);

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\OrgInvitation;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

function verificationUrl(User $user, ?string $hash = null): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $user->id,
        'hash' => $hash ?? sha1($user->email),
    ]);
}

test('sends a verification link when someone registers', function (): void {
    Notification::fake();

    $this->post(route('register'), [
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    Notification::assertSentTo(User::query()->where('email', 'ada@example.com')->sole(), VerifyEmail::class);
});

test('verifies the email from the signed link', function (): void {
    $user = User::factory()->unverified()->create();

    $response = $this->actingAs($user)->get(verificationUrl($user));

    $response->assertRedirect(route('home'));

    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});

test('refuses a link for a different address or with a broken signature', function (string $case): void {
    $user = User::factory()->unverified()->create();
    $url = $case === 'wrong address' ? verificationUrl($user, sha1('someone@else.example')) : verificationUrl($user).'tampered';

    $this->actingAs($user)->get($url)->assertForbidden();

    expect($user->refresh()->hasVerifiedEmail())->toBeFalse();
})->with(['wrong address', 'tampered signature']);

test('shows unverified users where their link went, and sends verified users on', function (): void {
    $unverified = User::factory()->unverified()->create(['email' => 'new@example.com']);

    $this->actingAs($unverified)->get(route('verification.notice'))->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->component('auth/VerifyEmail')->where('email', 'new@example.com'));
    $this->actingAs(User::factory()->create())->get(route('verification.notice'))->assertRedirect(route('home'));
});

test('sends the link again on request, but not to an already verified user', function (): void {
    Notification::fake();
    $unverified = User::factory()->unverified()->create();
    $verified = User::factory()->create();

    $this->actingAs($unverified)->post(route('verification.send'))->assertRedirect();
    $this->actingAs($verified)->post(route('verification.send'))->assertRedirect(route('home'));

    Notification::assertSentTo($unverified, VerifyEmail::class);
    Notification::assertNotSentTo($verified, VerifyEmail::class);
});

test('keeps unverified users from generating, buying credits or accepting invitations', function (): void {
    Queue::fake([SubmitGeneration::class]);
    $owner = organizationOwner();
    $owner->forceFill(['email_verified_at' => null])->save();
    OrgInvitation::factory()->withToken('secret')->create(['email' => $owner->email]);

    $this->actingAs($owner)->post(route('generations.store'), ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertRedirect(route('verification.notice'));
    $this->actingAs($owner)->post(route('credits.purchase'), ['pack' => 5, 'purchase_key' => (string) Str::uuid()])
        ->assertRedirect(route('verification.notice'));
    $this->actingAs($owner)->post(route('invitations.accept', 'secret'))
        ->assertRedirect(route('verification.notice'));

    expect(Generation::query()->count())->toBe(0);
    expect($owner->currentOrganization->refresh()->credit_balance)->toBe('0.0000000000');
    expect($owner->orgUsers()->count())->toBe(1);
    Queue::assertNothingPushed();
});

test('shares whether the user has verified their email', function (): void {
    $this->actingAs(User::factory()->unverified()->create())->get(route('home'))
        ->assertInertia(fn (Assert $page): Assert => $page->where('auth.user.email_verified', false));
});
