<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Role;

test('creates owner, admin and member roles with their default permissions when created', function (string $roleName, array $expectedPermissions): void {
    $organization = Organization::factory()->create();

    $role = $organization->roles()->where('name', $roleName)->sole();

    expect($role->permissions()->pluck('name')->map->value->sort()->values()->all())
        ->toBe($expectedPermissions);
})->with([
    'owner' => ['Owner', [
        'credits.buy',
        'generations.create',
        'members.invite',
        'members.remove',
        'members.view',
        'organization.delete',
        'organization.update',
        'roles.manage',
    ]],
    'admin' => ['Admin', [
        'credits.buy',
        'generations.create',
        'members.invite',
        'members.remove',
        'members.view',
        'organization.update',
        'roles.manage',
    ]],
    'member' => ['Member', [
        'generations.create',
        'members.view',
    ]],
]);

test('gives each organization its own default roles', function (): void {
    $first = Organization::factory()->create();
    $second = Organization::factory()->create();

    expect($first->roles()->orderBy('name')->pluck('name')->all())->toBe(['Admin', 'Member', 'Owner']);
    expect($second->roles()->orderBy('name')->pluck('name')->all())->toBe(['Admin', 'Member', 'Owner']);
    expect(Role::query()->count())->toBe(6);
});
