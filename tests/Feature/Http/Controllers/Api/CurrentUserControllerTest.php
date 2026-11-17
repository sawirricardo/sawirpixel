<?php

declare(strict_types=1);

use App\Enums\DefaultRole;
use App\Enums\TokenAbility;
use App\Models\User;

test('says who the token belongs to, which organization it acts in, and its scopes', function (): void {
    $user = organizationOwner();
    $token = apiToken($user, [TokenAbility::GenerationsRead]);

    $this->withToken($token)->getJson(route('api.user'))->assertOk()->assertJson(['data' => [
        'type' => 'users',
        'id' => (string) $user->id,
        'attributes' => [
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => true,
            'organization' => ['id' => $user->current_organization_id, 'name' => 'Acme Studio'],
            'token' => ['name' => 'Test token', 'abilities' => ['generations:read']],
        ],
    ]]);
});

test('keeps acting in the token organization after the user switches organizations', function (): void {
    $user = organizationOwner();
    $token = apiToken($user);
    $other = organizationOwner()->currentOrganization;
    addMember($other, DefaultRole::Member, $user);

    expect($user->refresh()->current_organization_id)->toBe($other->id);
    $this->withToken($token)->getJson(route('api.user'))->assertJsonPath('data.attributes.organization.name', 'Acme Studio');
});

test('rejects requests without a valid token, as JSON', function (string $case): void {
    $user = organizationOwner();
    $request = match ($case) {
        'no token' => $this,
        'wrong token' => $this->withToken('1|spx_not-a-real-token'),
        'expired token' => $this->withToken(apiToken($user, expiresAt: now()->subMinute())),
    };

    $request->get(route('api.user'))->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
})->with(['no token', 'wrong token', 'expired token']);

test('stops working once the user leaves the token organization', function (): void {
    $owner = organizationOwner();
    $member = addMember($owner->currentOrganization, DefaultRole::Member);
    $token = apiToken($member);
    $member->membershipIn($owner->currentOrganization)?->delete();

    $this->withToken($token)->getJson(route('api.user'))->assertForbidden()
        ->assertJson(['message' => "This token's organization no longer exists, or you're no longer a member of it."]);
});

test('records when a token was last used', function (): void {
    $user = organizationOwner();
    $token = apiToken($user);

    $this->withToken($token)->getJson(route('api.user'))->assertOk();

    expect(User::query()->find($user->id)?->tokens()->sole()->last_used_at)->not->toBeNull();
});
