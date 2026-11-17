<?php

declare(strict_types=1);

use App\Actions\CreateOrganization;
use App\Actions\IssuePersonalAccessToken;
use App\Enums\DefaultRole;
use App\Enums\TokenAbility;
use App\Models\CreditTransaction;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use Brick\Math\BigDecimal;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function (): void {
        Str::createRandomStringsNormally();
        Str::createUuidsNormally();
        Http::preventStrayRequests();
        Process::preventStrayProcesses();
        Sleep::fake();

        $this->freezeTime();
    })
    ->in('Browser', 'Feature', 'Unit');

expect()->extend('toBeOne', fn () => $this->toBe(1));

/**
 * A Pixelforge generation object, as returned by the API and embedded in webhook events.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function pixelforgeGenerationPayload(string $status, array $overrides = []): array
{
    return [
        'id' => 'gen_1',
        'object' => 'generation',
        'status' => $status,
        'size' => '1536x1024',
        'quality' => 'high',
        'image_url' => $status === 'succeeded' ? 'https://pixelforge.promptedit.dev/images/gen_1.png?signature=abc' : null,
        'image_url_expires_at' => $status === 'succeeded' ? '2026-11-18T03:31:00Z' : null,
        'usage' => $status === 'succeeded' ? [
            'input_text_tokens' => 12,
            'input_image_tokens' => 765,
            'output_tokens' => 6450,
            'reasoning_tokens' => 3184,
            'total_tokens' => 10411,
        ] : null,
        'rates' => [
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ],
        'error' => $status === 'failed' ? ['code' => 'generation_failed', 'message' => 'No image could be produced.'] : null,
        'metadata' => [],
        'completed_at' => in_array($status, ['succeeded', 'failed'], true) ? '2026-11-17T03:31:00Z' : null,
        ...$overrides,
    ];
}

/**
 * Fake Pixelforge's public pricing endpoint with its published rates and token tables.
 */
function fakePixelforgePricing(): void
{
    Http::fake([
        'pixelforge.promptedit.dev/v1/pricing' => Http::response([
            'object' => 'pricing',
            'currency' => 'usd',
            'unit' => 'per_1m_tokens',
            'rates' => [
                'input_text_tokens' => '5.00',
                'input_image_tokens' => '10.00',
                'output_tokens' => '40.00',
                'reasoning_tokens' => '12.50',
            ],
            'tokens' => [
                'characters_per_text_token' => 4,
                'text_token_variance' => 0.15,
                'per_reference_image' => 765,
                'output' => [
                    'low' => ['1024x1024' => 280, '1536x1024' => 420, '1024x1536' => 420],
                    'medium' => ['1024x1024' => 1100, '1536x1024' => 1650, '1024x1536' => 1650],
                    'high' => ['1024x1024' => 4300, '1536x1024' => 6450, '1024x1536' => 6450],
                ],
                'reasoning' => [
                    'low' => ['min' => 0, 'max' => 500],
                    'medium' => ['min' => 200, 'max' => 2000],
                    'high' => ['min' => 1000, 'max' => 8000],
                ],
            ],
        ]),
    ]);
}

/**
 * A user who owns a fresh organization (and so holds every permission in it).
 */
function organizationOwner(): User
{
    $user = User::factory()->create();

    resolve(CreateOrganization::class)->handle($user, 'Acme Studio');

    return $user->refresh();
}

/**
 * Add a user to an organization with one of its default roles, and make it their current organization.
 */
function addMember(Organization $organization, DefaultRole $role, ?User $user = null): User
{
    $user ??= User::factory()->create();

    $organization->orgUsers()->create(['user_id' => $user->id])->assignRole(
        $organization->roles()->where('name', $role->value)->sole(),
    );
    $user->currentOrganization()->associate($organization)->save();

    return $user->refresh();
}

/**
 * Add credits to an organization through the ledger, as a purchase by its first member (or a new user
 * if it has none).
 */
function fundOrganization(Organization $organization, string $amount): void
{
    $buyer = $organization->orgUsers()->oldest('id')->first()?->user()->first() ?? User::factory()->create();

    resolve(CreditLedger::class)->purchase($organization, $buyer, BigDecimal::of($amount), (string) Str::uuid());
}

/**
 * The organization's cached balance must always equal the sum of its ledger.
 */
function expectBalanceMatchesLedger(Organization $organization): void
{
    $sum = CreditTransaction::query()
        ->where('organization_id', $organization->id)
        ->pluck('amount')
        ->reduce(fn (BigDecimal $total, mixed $amount): BigDecimal => $total->plus(BigDecimal::of(is_string($amount) ? $amount : '0')), BigDecimal::zero());

    expect(BigDecimal::of($organization->refresh()->credit_balance)->isEqualTo($sum))->toBeTrue();
}

/**
 * A real personal access token for the user, in their current organization unless another is given,
 * with the given scopes. Returns the plain-text secret to send as a bearer token.
 *
 * @param  list<TokenAbility>  $abilities
 */
function apiToken(User $user, array $abilities = [TokenAbility::GenerationsRead, TokenAbility::GenerationsCreate, TokenAbility::CreditsRead], ?Organization $organization = null, ?CarbonInterface $expiresAt = null): string
{
    $organization ??= $user->currentOrganization ?? throw new LogicException('The user has no current organization.');

    return resolve(IssuePersonalAccessToken::class)->handle($user, $organization, 'Test token', $abilities, $expiresAt)['plainText'];
}
