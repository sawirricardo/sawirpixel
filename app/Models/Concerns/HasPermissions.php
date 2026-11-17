<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\Permission as PermissionEnum;
use App\Models\Permission;
use App\Models\Permissionable;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasPermissions
{
    /**
     * Permissions granted directly to this model.
     *
     * @return MorphToMany<Permission, $this, Permissionable>
     */
    public function permissions(): MorphToMany
    {
        return $this->morphToMany(Permission::class, 'permissionable')
            ->using(Permissionable::class)
            ->withTimestamps();
    }

    public function givePermissionTo(PermissionEnum ...$permissions): void
    {
        $this->permissions()->syncWithoutDetaching(
            array_map(
                fn (PermissionEnum $permission): int => Permission::query()->firstOrCreate(['name' => $permission])->id,
                $permissions,
            ),
        );
    }

    public function revokePermissionTo(PermissionEnum ...$permissions): void
    {
        $this->permissions()->detach(
            Permission::query()->whereIn('name', $permissions)->pluck('id'),
        );
    }

    public function hasDirectPermission(PermissionEnum $permission): bool
    {
        return $this->permissions()->where('name', $permission)->exists();
    }

    /**
     * Determine whether this model holds the permission.
     *
     * Models that also use HasRoles override this to include role-granted permissions.
     */
    public function hasPermission(PermissionEnum $permission): bool
    {
        return $this->hasDirectPermission($permission);
    }
}
