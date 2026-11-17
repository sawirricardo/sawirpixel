<?php

declare(strict_types=1);

use App\Enums\Permission;
use App\Models\Organization;
use App\Models\OrgUser;
use App\Models\Role;
use App\Models\User;

test('grants a permission given directly to the membership', function (): void {
    $orgUser = OrgUser::factory()->create();

    $orgUser->givePermissionTo(Permission::InviteMembers);

    expect($orgUser->hasPermission(Permission::InviteMembers))->toBeTrue();
    expect($orgUser->hasPermission(Permission::RemoveMembers))->toBeFalse();
});

test('grants a permission through an assigned role', function (): void {
    $organization = Organization::factory()->create();
    $orgUser = OrgUser::factory()->for($organization)->create();
    $role = Role::factory()->for($organization)->create();
    $role->givePermissionTo(Permission::ManageRoles);

    $orgUser->assignRole($role);

    expect($orgUser->hasPermission(Permission::ManageRoles))->toBeTrue();
    expect($orgUser->hasDirectPermission(Permission::ManageRoles))->toBeFalse();
    expect($orgUser->hasRole($role))->toBeTrue();
    expect($orgUser->hasRole($role->name))->toBeTrue();
});

test('denies a permission that neither the membership nor its roles hold', function (): void {
    $organization = Organization::factory()->create();
    $orgUser = OrgUser::factory()->for($organization)->create();
    $role = Role::factory()->for($organization)->create();
    $role->givePermissionTo(Permission::ViewMembers);

    $orgUser->assignRole($role);

    expect($orgUser->hasPermission(Permission::DeleteOrganization))->toBeFalse();
});

test('grants permissions from every assigned role', function (): void {
    $organization = Organization::factory()->create();
    $orgUser = OrgUser::factory()->for($organization)->create();
    $viewer = Role::factory()->for($organization)->create();
    $viewer->givePermissionTo(Permission::ViewMembers);

    $manager = Role::factory()->for($organization)->create();
    $manager->givePermissionTo(Permission::RemoveMembers);

    $orgUser->assignRole($viewer, $manager);

    expect($orgUser->hasPermission(Permission::ViewMembers))->toBeTrue();
    expect($orgUser->hasPermission(Permission::RemoveMembers))->toBeTrue();
});

test('rejects a role defined by another organization', function (): void {
    $orgUser = OrgUser::factory()->create();
    $foreignRole = Role::factory()->create();
    $foreignRole->givePermissionTo(Permission::DeleteOrganization);

    expect(fn () => $orgUser->assignRole($foreignRole))->toThrow(InvalidArgumentException::class);
    expect($orgUser->hasPermission(Permission::DeleteOrganization))->toBeFalse();
});

test('assigns no roles when any role in the batch belongs to another organization', function (): void {
    $organization = Organization::factory()->create();
    $orgUser = OrgUser::factory()->for($organization)->create();
    $ownRole = Role::factory()->for($organization)->create();
    $foreignRole = Role::factory()->create();

    expect(fn () => $orgUser->assignRole($ownRole, $foreignRole))->toThrow(InvalidArgumentException::class);
    expect($orgUser->roles()->count())->toBe(0);
});

test("does not grant permissions from the same user's membership in another organization", function (): void {
    $user = User::factory()->create();
    $adminMembership = OrgUser::factory()->for($user)->create();
    $admin = Role::factory()->for($adminMembership->organization)->create();
    $admin->givePermissionTo(Permission::DeleteOrganization);

    $adminMembership->assignRole($admin);
    $adminMembership->givePermissionTo(Permission::UpdateOrganization);

    $otherMembership = OrgUser::factory()->for($user)->create();

    expect($otherMembership->hasPermission(Permission::DeleteOrganization))->toBeFalse();
    expect($otherMembership->hasPermission(Permission::UpdateOrganization))->toBeFalse();
});

test('revokes the permission when the role granting it is removed', function (): void {
    $organization = Organization::factory()->create();
    $orgUser = OrgUser::factory()->for($organization)->create();
    $role = Role::factory()->for($organization)->create();
    $role->givePermissionTo(Permission::ManageRoles);

    $orgUser->assignRole($role);

    $orgUser->removeRole($role);

    expect($orgUser->hasRole($role))->toBeFalse();
    expect($orgUser->hasPermission(Permission::ManageRoles))->toBeFalse();
});

test('revokes a direct permission without affecting the others', function (): void {
    $orgUser = OrgUser::factory()->create();
    $orgUser->givePermissionTo(Permission::ViewMembers, Permission::InviteMembers);

    $orgUser->revokePermissionTo(Permission::InviteMembers);

    expect($orgUser->hasPermission(Permission::InviteMembers))->toBeFalse();
    expect($orgUser->hasPermission(Permission::ViewMembers))->toBeTrue();
});

test('ignores a permission or role that is granted twice', function (): void {
    $organization = Organization::factory()->create();
    $orgUser = OrgUser::factory()->for($organization)->create();
    $role = Role::factory()->for($organization)->create();

    $orgUser->givePermissionTo(Permission::ViewMembers);
    $orgUser->givePermissionTo(Permission::ViewMembers);
    $orgUser->assignRole($role);
    $orgUser->assignRole($role);

    expect($orgUser->permissions()->count())->toBe(1);
    expect($orgUser->roles()->count())->toBe(1);
});

test('revokes a permission from the membership only, not from a role sharing its id', function (): void {
    $orgUser = OrgUser::factory()->create(['id' => 1000]);
    $role = Role::factory()->create(['id' => 1000]);
    $orgUser->givePermissionTo(Permission::ViewMembers);
    $role->givePermissionTo(Permission::ViewMembers);

    $orgUser->revokePermissionTo(Permission::ViewMembers);

    expect($orgUser->hasPermission(Permission::ViewMembers))->toBeFalse();
    expect($role->hasPermission(Permission::ViewMembers))->toBeTrue();
});

test('lists only its own memberships for a user and an organization', function (): void {
    $user = User::factory()->create();
    $organization = Organization::factory()->create();
    $membership = OrgUser::factory()->for($user)->for($organization)->create();
    OrgUser::factory()->create();

    expect($user->orgUsers()->pluck('id')->all())->toBe([$membership->id]);
    expect($organization->orgUsers()->pluck('id')->all())->toBe([$membership->id]);
});
