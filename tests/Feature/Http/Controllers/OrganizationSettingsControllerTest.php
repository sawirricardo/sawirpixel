<?php

declare(strict_types=1);

use App\Enums\DefaultRole;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\Organization;
use App\Models\OrgInvitation;
use App\Models\OrgUser;
use App\Services\Billing\CreditLedger;
use Brick\Math\BigDecimal;
use Inertia\Testing\AssertableInertia as Assert;

test('shows the organization settings with its members on one page', function (): void {
    $owner = organizationOwner();

    $response = $this->actingAs($owner)->get(route('organization.settings'));

    $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component('organizations/Settings')
        ->where('organization.name', 'Acme Studio')
        ->where('canUpdate', true)
        ->has('members', 1));
});

test('renames the organization', function (): void {
    $owner = organizationOwner();

    $response = $this->actingAs($owner)->patch(route('organization.update'), ['name' => 'Acme Labs']);

    $response->assertRedirect()->assertSessionHasNoErrors();
    expect($owner->currentOrganization->refresh()->name)->toBe('Acme Labs');
});

test('requires a name', function (): void {
    $owner = organizationOwner();

    $this->actingAs($owner)->patch(route('organization.update'), ['name' => ''])
        ->assertSessionHasErrors(['name' => 'The name field is required.']);

    expect($owner->currentOrganization->refresh()->name)->toBe('Acme Studio');
});

test('lets members see the settings but not rename the organization', function (): void {
    $organization = organizationOwner()->currentOrganization;
    $member = addMember($organization, DefaultRole::Member);

    $this->actingAs($member)->get(route('organization.settings'))->assertInertia(fn (Assert $page): Assert => $page->where('canUpdate', false));
    $this->actingAs($member)->patch(route('organization.update'), ['name' => 'Hijacked'])->assertForbidden();

    expect($organization->refresh()->name)->toBe('Acme Studio');
});

test('keeps the old members address working', function (): void {
    $this->actingAs(organizationOwner())->get('/organization/members')->assertRedirect('/organization/settings');
});

describe('destroy', function (): void {
    test('removes the organization for everyone but keeps its billing records', function (): void {
        $owner = organizationOwner();
        $organization = $owner->currentOrganization;
        $member = addMember($organization, DefaultRole::Member);
        $elsewhere = Organization::factory()->create(['name' => 'Elsewhere']);
        addMember($elsewhere, DefaultRole::Member, $member);
        $member->currentOrganization()->associate($organization)->save();
        $invitation = OrgInvitation::factory()->for($organization)->create();
        fundOrganization($organization, '5');
        $generation = Generation::factory()->queued()->for($owner)->for($organization)->create([
            'status' => GenerationStatus::Succeeded,
            'completed_at' => now(),
            'charge' => '0.3818875000',
            'provider_cost' => '0.305510000',
        ]);

        $response = $this->actingAs($owner)->delete(route('organization.destroy'), ['confirm_name' => 'Acme Studio']);

        $response->assertRedirect(route('home'));
        $this->assertSoftDeleted($organization);
        expect(OrgUser::query()->where('organization_id', $organization->id)->count())->toBe(0);
        $this->assertModelMissing($invitation);
        expect($owner->refresh()->current_organization_id)->toBeNull();
        expect($member->refresh()->current_organization_id)->toBe($elsewhere->id);
        $this->assertModelExists($generation);
        expect(CreditTransaction::query()->where('organization_id', $organization->id)->count())->toBe(1);
    });

    test('requires the exact organization name, commas and all', function (string $typed, bool $deletes): void {
        $owner = organizationOwner();
        $owner->currentOrganization->update(['name' => 'Acme, Inc']);

        $response = $this->actingAs($owner)->delete(route('organization.destroy'), ['confirm_name' => $typed]);

        if ($deletes) {
            $this->assertSoftDeleted($owner->currentOrganization);

            return;
        }

        $response->assertSessionHasErrors(['confirm_name']);
        $this->assertNotSoftDeleted($owner->currentOrganization);
    })->with([
        'exact' => ['Acme, Inc', true],
        'part of it' => ['Acme', false],
        'different case' => ['acme, inc', false],
        'empty' => ['', false],
    ]);

    test('waits for images still in progress', function (): void {
        $owner = organizationOwner();
        Generation::factory()->queued()->for($owner)->for($owner->currentOrganization)->count(2)->create();

        $response = $this->actingAs($owner)->delete(route('organization.destroy'), ['confirm_name' => 'Acme Studio']);

        $response->assertSessionHasErrors(['confirm_name' => 'Wait for the 2 images still in progress to finish before deleting Acme Studio.']);
        $this->assertNotSoftDeleted($owner->currentOrganization);
        expect($owner->refresh()->current_organization_id)->not->toBeNull();
    });

    test('is only for those allowed to delete it', function (DefaultRole $role): void {
        $organization = organizationOwner()->currentOrganization;
        $user = addMember($organization, $role);

        $this->actingAs($user)->delete(route('organization.destroy'), ['confirm_name' => 'Acme Studio'])->assertForbidden();

        $this->assertNotSoftDeleted($organization);
    })->with([DefaultRole::Admin, DefaultRole::Member]);

    test('offers deletion and warns about remaining credits only to those who can delete', function (): void {
        $owner = organizationOwner();
        fundOrganization($owner->currentOrganization, '5');
        $admin = addMember($owner->currentOrganization, DefaultRole::Admin);

        $this->actingAs($owner->refresh())->get(route('organization.settings'))->assertInertia(fn (Assert $page): Assert => $page
            ->where('canDelete', true)
            ->where('hasCredits', true)
            ->where('creditBalance.formatted', '$5.00'));
        $this->actingAs($admin)->get(route('organization.settings'))->assertInertia(fn (Assert $page): Assert => $page->where('canDelete', false));
    });

    test("still settles a late result for a deleted organization's generation", function (): void {
        $organization = Organization::factory()->create();
        fundOrganization($organization, '1');
        $generation = Generation::factory()->queued()->for($organization)->create();
        $organization->delete();

        resolve(CreditLedger::class)->settle($generation, BigDecimal::of('0.25'));

        expect(Organization::withTrashed()->find($organization->id)?->credit_balance)->toBe('0.7500000000');
    });
});
