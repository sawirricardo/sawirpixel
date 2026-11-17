<?php

declare(strict_types=1);

use App\Enums\CreditTransactionType;
use App\Enums\DefaultRole;
use App\Models\CreditTransaction;
use App\Models\User;
use Illuminate\Support\Str;

test('adds a pack of credits to the current organization', function (): void {
    $owner = organizationOwner();

    $response = $this->actingAs($owner)->post(route('credits.purchase'), ['pack' => 25, 'purchase_key' => (string) Str::uuid()]);

    $response->assertRedirect()->assertSessionHasNoErrors();
    expect($owner->currentOrganization->refresh()->credit_balance)->toBe('25.0000000000');
    $purchase = CreditTransaction::query()->sole();
    expect($purchase->type)->toBe(CreditTransactionType::Purchase);
    expect($purchase->user_id)->toBe($owner->id);
    expect($purchase->amount)->toBe('25.0000000000');
});

test('buys only once when the same purchase is replayed', function (): void {
    $owner = organizationOwner();
    $key = (string) Str::uuid();

    $this->actingAs($owner)->post(route('credits.purchase'), ['pack' => 10, 'purchase_key' => $key]);
    $this->actingAs($owner)->post(route('credits.purchase'), ['pack' => 10, 'purchase_key' => $key]);

    expect(CreditTransaction::query()->count())->toBe(1);
    expect($owner->currentOrganization->refresh()->credit_balance)->toBe('10.0000000000');
    expectBalanceMatchesLedger($owner->currentOrganization);
});

test('only offers the fixed packs', function (array $input, string $field): void {
    $owner = organizationOwner();

    $this->actingAs($owner)->post(route('credits.purchase'), [...['pack' => 10, 'purchase_key' => (string) Str::uuid()], ...$input])
        ->assertSessionHasErrors($field);

    expect(CreditTransaction::query()->count())->toBe(0);
})->with([
    'an arbitrary amount' => [['pack' => 7], 'pack'],
    'a negative amount' => [['pack' => -10], 'pack'],
    'a missing purchase key' => [['purchase_key' => ''], 'purchase_key'],
]);

test('lets an admin buy but not a member', function (DefaultRole $role, bool $allowed): void {
    $organization = organizationOwner()->currentOrganization;
    $user = addMember($organization, $role);

    $response = $this->actingAs($user)->post(route('credits.purchase'), ['pack' => 5, 'purchase_key' => (string) Str::uuid()]);

    $allowed ? $response->assertRedirect() : $response->assertForbidden();
    expect($organization->refresh()->credit_balance)->toBe($allowed ? '5.0000000000' : '0.0000000000');
})->with([
    'admin' => [DefaultRole::Admin, true],
    'member' => [DefaultRole::Member, false],
]);

test('needs a current organization', function (): void {
    $this->actingAs(User::factory()->create())
        ->post(route('credits.purchase'), ['pack' => 5, 'purchase_key' => (string) Str::uuid()])
        ->assertNotFound();
});

test('shares the balance, what is set aside and whether the user can buy', function (): void {
    $owner = organizationOwner();
    fundOrganization($owner->currentOrganization, '12.5');
    $owner->refresh();

    $this->actingAs($owner)->get(route('home'))->assertInertia(fn ($page) => $page
        ->where('auth.credits.balance', ['amount' => '12.5000000000', 'formatted' => '$12.50'])
        ->where('auth.credits.reserved', ['amount' => '0', 'formatted' => '$0.00'])
        ->where('auth.credits.canBuy', true));
});
