<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function tokenInput(User $user, array $overrides = []): array
{
    return [
        'name' => 'CI pipeline',
        'organization_id' => $user->current_organization_id,
        'abilities' => ['generations:read', 'generations:create'],
        'expiration' => '30',
        ...$overrides,
    ];
}

describe('creating', function (): void {
    test('issues a token bound to an organization and shows its secret exactly once', function (): void {
        $this->freezeTime();
        $user = organizationOwner();

        $response = $this->actingAs($user)->from(route('profile.edit'))->post(route('tokens.store'), tokenInput($user));

        $response->assertRedirect(route('profile.edit'))->assertSessionHasNoErrors();
        $token = PersonalAccessToken::query()->sole();
        expect($token->name)->toBe('CI pipeline');
        expect($token->organization_id)->toBe($user->current_organization_id);
        expect($token->abilities)->toBe(['generations:read', 'generations:create']);
        expect($token->expires_at?->toDateTimeString())->toBe(now()->addDays(30)->toDateTimeString());

        $flashed = session('inertia.flash_data.newToken');
        expect($flashed)->toBeArray()->and($flashed['id'])->toBe($token->id);
        expect($flashed['plainText'])->toStartWith($token->id.'|spx_');
        expect(hash('sha256', explode('|', $flashed['plainText'])[1]))->toBe($token->token);
    });

    test('can be made without an expiry date', function (): void {
        $user = organizationOwner();

        $this->actingAs($user)->post(route('tokens.store'), tokenInput($user, ['expiration' => 'none']))->assertSessionHasNoErrors();

        expect(PersonalAccessToken::query()->sole()->expires_at)->toBeNull();
    });

    test('refuses an organization the user is not in, unknown scopes, and no scopes', function (array $input, string $field): void {
        $user = organizationOwner();

        $this->actingAs($user)->post(route('tokens.store'), tokenInput($user, $input))->assertSessionHasErrors($field);

        expect(PersonalAccessToken::query()->count())->toBe(0);
    })->with([
        'foreign organization' => [fn (): array => ['organization_id' => Organization::factory()->create()->id], 'organization_id'],
        'unknown scope' => [['abilities' => ['admin:everything']], 'abilities.0'],
        'no scopes' => [['abilities' => []], 'abilities'],
        'unknown expiry' => [['expiration' => '365'], 'expiration'],
        'no note' => [['name' => ''], 'name'],
    ]);
});

describe('managing', function (): void {
    test('lists the user own tokens with their scopes, organization and dates', function (): void {
        $user = organizationOwner();
        $this->actingAs($user)->post(route('tokens.store'), tokenInput($user));
        $someoneElse = organizationOwner();
        $this->actingAs($someoneElse)->post(route('tokens.store'), tokenInput($someoneElse));

        $this->actingAs($user)->get(route('profile.edit'))->assertInertia(fn (Assert $page): Assert => $page
            ->has('tokens', 1)
            ->where('tokens.0.name', 'CI pipeline')
            ->where('tokens.0.organization', 'Acme Studio')
            ->where('tokens.0.abilities', ['generations:read', 'generations:create'])
            ->where('tokens.0.last_used_at', null)
            ->where('tokens.0.expired', false)
            ->where('tokenOptions.expirationDays', [7, 30, 60, 90])
            ->has('tokenOptions.abilities', 3)
            ->where('tokenOptions.organizations.0.name', 'Acme Studio'));
    });

    test('renames a token and changes its scopes', function (): void {
        $user = organizationOwner();
        $this->actingAs($user)->post(route('tokens.store'), tokenInput($user));
        $token = PersonalAccessToken::query()->sole();

        $this->actingAs($user)->patch(route('tokens.update', $token->id), ['name' => 'Read only', 'abilities' => ['generations:read']])->assertSessionHasNoErrors();

        $token->refresh();
        expect($token->name)->toBe('Read only');
        expect($token->abilities)->toBe(['generations:read']);
    });

    test('regenerates a token: a new secret and expiry, the old secret stops working', function (): void {
        $user = organizationOwner();
        $this->actingAs($user)->post(route('tokens.store'), tokenInput($user));
        $token = PersonalAccessToken::query()->sole();
        $oldHash = $token->token;

        $this->actingAs($user)->post(route('tokens.regenerate', $token->id), ['expiration' => '7'])->assertSessionHasNoErrors();

        $token->refresh();
        expect($token->token)->not->toBe($oldHash);
        expect($token->expires_at?->isFuture())->toBeTrue();
        expect($token->name)->toBe('CI pipeline');
    });

    test('deletes a token', function (): void {
        $user = organizationOwner();
        $this->actingAs($user)->post(route('tokens.store'), tokenInput($user));

        $this->actingAs($user)->delete(route('tokens.destroy', PersonalAccessToken::query()->sole()->id))->assertRedirect();

        expect(PersonalAccessToken::query()->count())->toBe(0);
    });

    test("can't touch someone else's token", function (string $action): void {
        $owner = organizationOwner();
        $this->actingAs($owner)->post(route('tokens.store'), tokenInput($owner));
        $token = PersonalAccessToken::query()->sole();
        $intruder = User::factory()->create();

        $response = match ($action) {
            'update' => $this->actingAs($intruder)->patch(route('tokens.update', $token->id), ['name' => 'Mine now', 'abilities' => ['generations:read']]),
            'regenerate' => $this->actingAs($intruder)->post(route('tokens.regenerate', $token->id), ['expiration' => '7']),
            'delete' => $this->actingAs($intruder)->delete(route('tokens.destroy', $token->id)),
        };

        $response->assertNotFound();

        expect($token->fresh()?->name)->toBe('CI pipeline');
    })->with(['update', 'regenerate', 'delete']);

    test('cannot be managed with a token, only a logged-in session', function (): void {
        $user = organizationOwner();
        $this->actingAs($user)->post(route('tokens.store'), tokenInput($user));
        auth()->forgetGuards();
        $plainText = $user->createToken('other', ['generations:read'])->plainTextToken;

        $this->withToken($plainText)->postJson(route('tokens.store'), tokenInput($user))->assertUnauthorized();
    });
});
