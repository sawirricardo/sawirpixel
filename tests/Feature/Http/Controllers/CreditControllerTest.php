<?php

declare(strict_types=1);

use App\Enums\DefaultRole;
use App\Models\Generation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use Brick\Math\BigDecimal;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Ada's organization: $1 bought, then one image reserved at $0.50 and charged $0.3818875.
 *
 * @return array{ada: User, generation: Generation}
 */
function creditHistory(): array
{
    $ada = organizationOwner();
    $organization = $ada->currentOrganization;
    $ledger = resolve(CreditLedger::class);
    $ledger->purchase($organization, $ada, BigDecimal::of('1'), 'b7b9f5c2-6e1d-4c39-9d5e-6b1c2a0e4f11');

    $generation = Generation::factory()->queued()->for($ada)->for($organization)->create(['prompt' => 'A lighthouse at dawn']);
    $ledger->hold($ledger->lockOrganization($organization->id), $generation, BigDecimal::of('0.5'));
    $ledger->settle($generation, BigDecimal::of('0.3818875'));

    return ['ada' => $ada->refresh(), 'generation' => $generation];
}

test("lists the organization's credit entries newest first with exact amounts and running balance", function (): void {
    ['ada' => $ada, 'generation' => $generation] = creditHistory();

    $response = $this->actingAs($ada)->get(route('credits.index'));

    $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('credits/Index')
        ->where('summary.balance', ['amount' => '0.6181125000', 'formatted' => '$0.62'])
        ->where('summary.reserved', ['amount' => '0', 'formatted' => '$0.00'])
        ->where('summary.purchased.amount', '1.0000000000')
        ->where('summary.charged.amount', '0.3818875000')
        ->where('canBuy', true)
        ->has('entries.data', 4)
        ->where('entries.data.0.label', 'Charged')
        ->where('entries.data.0.amount', ['amount' => '-0.3818875000', 'formatted' => '-$0.38'])
        ->where('entries.data.0.balance_after.amount', '0.6181125000')
        ->where('entries.data.0.generation', ['id' => $generation->id, 'prompt' => 'A lighthouse at dawn', 'is_yours' => true])
        ->where('entries.data.0.member', $ada->name)
        ->where('entries.data.1.label', 'Returned')
        ->where('entries.data.1.amount.amount', '0.5000000000')
        ->where('entries.data.2.label', 'Reserved')
        ->where('entries.data.2.amount.amount', '-0.5000000000')
        ->where('entries.data.3.label', 'Purchase')
        ->where('entries.data.3.generation', null)
        ->where('entries.data.3.balance_after.amount', '1.0000000000'));
});

test('filters to purchases or charges', function (string $type, string $label): void {
    ['ada' => $ada] = creditHistory();

    $response = $this->actingAs($ada)->get(route('credits.index', ['type' => $type]));

    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('filters.type', $type)
        ->has('entries.data', 1)
        ->where('entries.data.0.label', $label));
})->with([
    'purchases' => ['purchase', 'Purchase'],
    'charges' => ['charge', 'Charged'],
]);

test('rejects filtering by an internal entry type', function (): void {
    ['ada' => $ada] = creditHistory();

    $this->actingAs($ada)->get(route('credits.index', ['type' => 'hold']))->assertSessionHasErrors('type');
});

test("shows a member the shared history without linking to teammates' images", function (): void {
    ['ada' => $ada] = creditHistory();
    $member = addMember($ada->currentOrganization, DefaultRole::Member);

    $response = $this->actingAs($member)->get(route('credits.index'));

    $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->where('canBuy', false)
        ->has('entries.data', 4)
        ->where('entries.data.0.generation.is_yours', false));
});

test("never shows another organization's entries", function (): void {
    creditHistory();
    $stranger = organizationOwner();

    $this->actingAs($stranger)->get(route('credits.index'))->assertInertia(fn (Assert $page): Assert => $page
        ->has('entries.data', 0)
        ->where('summary.balance.amount', '0.0000000000'));
});

test('asks a user without an organization to create one first', function (): void {
    $this->actingAs(User::factory()->create())->get(route('credits.index'))->assertRedirect(route('organizations.create'));
});

test('forbids a user whose current organization they are no longer a member of', function (): void {
    $user = User::factory()->create();
    $user->currentOrganization()->associate(Organization::factory()->create())->save();

    $this->actingAs($user)->get(route('credits.index'))->assertForbidden();
});

test('pages through long histories', function (): void {
    $owner = organizationOwner();
    foreach (range(1, 26) as $i) {
        fundOrganization($owner->currentOrganization, '1');
    }

    $this->actingAs($owner)->get(route('credits.index', ['page' => 2]))->assertInertia(fn (Assert $page): Assert => $page
        ->has('entries.data', 1)
        ->where('entries.data.0.balance_after.amount', '1.0000000000'));
});
