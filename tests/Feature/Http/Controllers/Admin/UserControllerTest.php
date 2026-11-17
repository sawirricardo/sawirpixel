<?php

declare(strict_types=1);

use App\Enums\DefaultRole;
use App\Enums\GenerationStatus;
use App\Enums\Permission;
use App\Models\Generation;
use App\Models\Organization;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    config()->set('app.admins', ['ops@example.com']);
});

function adminOperator(): User
{
    return User::factory()->create(['name' => 'Ops', 'email' => 'ops@example.com']);
}

function billedGeneration(User $user, string $charge, string $providerCost): Generation
{
    return Generation::factory()->queued()->for($user)->create([
        'status' => GenerationStatus::Succeeded,
        'completed_at' => now(),
        'charge' => $charge,
        'provider_cost' => $providerCost,
    ]);
}

describe('index', function (): void {
    test('lists every account newest first with exact lifetime charges', function (): void {
        $operator = adminOperator();
        $ada = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
        addMember(Organization::factory()->create(), DefaultRole::Member, $ada);
        billedGeneration($ada, '0.3818875000', '0.305510000');
        billedGeneration($ada, '0.0140000000', '0.011200000');
        Generation::factory()->for($ada)->create(['status' => GenerationStatus::Failed, 'charge' => '0']);

        $response = $this->actingAs($operator)->get(route('admin.users.index'));

        $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('admin/users/Index')
            ->has('users.data', 2)
            ->where('users.data.0.email', 'ada@example.com')
            ->where('users.data.0.organizations', 1)
            ->where('users.data.0.generations', 3)
            ->where('users.data.0.charged', ['amount' => '0.3958875000', 'formatted' => '$0.40'])
            ->where('users.data.0.is_super_admin', false)
            ->where('users.data.1.email', 'ops@example.com')
            ->where('users.data.1.is_super_admin', true)
            ->where('users.data.1.charged', ['amount' => '0', 'formatted' => '$0.00']));
    });

    test('finds users by part of their name or email', function (string $search, string $expected): void {
        $operator = adminOperator();
        User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.com']);
        User::factory()->create(['name' => 'Grace Hopper', 'email' => 'grace@navy.example']);

        $response = $this->actingAs($operator)->get(route('admin.users.index', ['search' => $search]));

        $response->assertInertia(fn (Assert $page): Assert => $page
            ->where('filters.search', $search)
            ->has('users.data', 1)
            ->where('users.data.0.email', $expected));
    })->with([
        'by name' => ['lovelace', 'ada@example.com'],
        'by email' => ['navy', 'grace@navy.example'],
    ]);

    test('pages through accounts', function (): void {
        $operator = adminOperator();
        User::factory()->count(20)->create();

        $response = $this->actingAs($operator)->get(route('admin.users.index', ['page' => 2]));

        $response->assertInertia(fn (Assert $page): Assert => $page
            ->has('users.data', 1)
            ->where('users.data.0.email', 'ops@example.com')
            ->where('users.total', 21));
    });
});

describe('show', function (): void {
    test("shows a user's organizations, access, totals and recent generations", function (): void {
        $operator = adminOperator();
        $ada = User::factory()->create(['name' => 'Ada', 'email' => 'ada@example.com']);
        $studio = Organization::factory()->create(['name' => 'Studio']);
        addMember($studio, DefaultRole::Admin, $ada);
        $ada->membershipIn($studio)?->givePermissionTo(Permission::DeleteOrganization);
        $generation = billedGeneration($ada, '0.3818875000', '0.305510000');

        $response = $this->actingAs($operator)->get(route('admin.users.show', $ada));

        $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('admin/users/Show')
            ->where('user.email', 'ada@example.com')
            ->where('user.current_organization_id', $studio->id)
            ->where('totals.generations', 1)
            ->where('totals.charged', ['amount' => '0.3818875000', 'formatted' => '$0.38'])
            ->where('totals.provider_cost', ['amount' => '0.305510000', 'formatted' => '$0.305510000'])
            ->where('totals.profit', ['amount' => '0.0763775000', 'formatted' => '$0.08'])
            ->where('memberships', [[
                'organization_id' => $studio->id,
                'organization' => 'Studio',
                'roles' => ['Admin'],
                'permissions' => ['Delete the organization'],
                'joined_at' => $ada->membershipIn($studio)?->created_at?->toIso8601String(),
            ]])
            ->has('generations', 1)
            ->where('generations.0.id', $generation->id)
            ->where('generations.0.status', 'succeeded')
            ->where('generations.0.charge.amount', '0.3818875000'));
    });

    test('returns 404 for an unknown user', function (): void {
        $this->actingAs(adminOperator())->get('/admin/users/999999')->assertNotFound();
    });
});

test('is only for operators', function (string $route): void {
    $member = User::factory()->create(['email' => 'member@example.com']);

    $this->actingAs($member)->get(route($route, ['user' => $member]))->assertForbidden();
    $this->post(route('logout'));
    $this->get(route($route, ['user' => $member]))->assertRedirect(route('login'));
})->with([
    'list' => ['admin.users.index'],
    'detail' => ['admin.users.show'],
]);
