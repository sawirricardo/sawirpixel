<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Enums\Permission as PermissionEnum;
use App\Models\Role;
use App\Models\Roleable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use InvalidArgumentException;

trait HasRoles
{
    use HasPermissions;

    /**
     * Determine whether the role may be assigned to this model, e.g. it belongs to the same organization.
     */
    abstract public function canBeAssignedRole(Role $role): bool;

    /**
     * @return MorphToMany<Role, $this, Roleable>
     */
    public function roles(): MorphToMany
    {
        return $this->morphToMany(Role::class, 'roleable')
            ->using(Roleable::class)
            ->withTimestamps();
    }

    /**
     * @throws InvalidArgumentException when a role cannot be assigned to this model
     */
    public function assignRole(Role ...$roles): void
    {
        foreach ($roles as $role) {
            throw_unless(
                $this->canBeAssignedRole($role),
                InvalidArgumentException::class,
                sprintf('Role [%d] cannot be assigned to this [%s].', $role->id, $this->getMorphClass()),
            );
        }

        $this->roles()->syncWithoutDetaching(
            array_map(fn (Role $role): int => $role->id, $roles),
        );
    }

    /**
     * Replace all of this model's roles with the given ones.
     *
     * @throws InvalidArgumentException when a role cannot be assigned to this model
     */
    public function syncRoles(Role ...$roles): void
    {
        foreach ($roles as $role) {
            throw_unless(
                $this->canBeAssignedRole($role),
                InvalidArgumentException::class,
                sprintf('Role [%d] cannot be assigned to this [%s].', $role->id, $this->getMorphClass()),
            );
        }

        $this->roles()->sync(array_map(fn (Role $role): int => $role->id, $roles));
    }

    public function removeRole(Role ...$roles): void
    {
        $this->roles()->detach(
            array_map(fn (Role $role): int => $role->id, $roles),
        );
    }

    public function hasRole(Role|string $role): bool
    {
        return $role instanceof Role
            ? $this->roles()->whereKey($role->id)->exists()
            : $this->roles()->where('name', $role)->exists();
    }

    public function hasPermissionThroughRole(PermissionEnum $permission): bool
    {
        return $this->roles()
            ->whereHas('permissions', fn (Builder $query): Builder => $query->where('name', $permission))
            ->exists();
    }

    /**
     * Determine whether this model holds the permission directly or through any of its roles.
     */
    public function hasPermission(PermissionEnum $permission): bool
    {
        return $this->hasDirectPermission($permission)
            || $this->hasPermissionThroughRole($permission);
    }
}
