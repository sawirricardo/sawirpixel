<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DefaultRole;
use Database\Factories\OrganizationFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property-read int $id
 * @property string $name
 */
final class Organization extends Model
{
    /** @use HasFactory<OrganizationFactory> */
    use HasFactory;

    /**
     * Give every new organization its default roles.
     */
    #[Boot]
    public static function createDefaultRolesOnCreation(): void
    {
        self::created(fn (Organization $organization) => $organization->createDefaultRoles());
    }

    /**
     * Create the Owner, Admin and Member roles with their default permissions.
     */
    public function createDefaultRoles(): void
    {
        foreach (DefaultRole::cases() as $defaultRole) {
            $this->roles()
                ->firstOrCreate(['name' => $defaultRole->value])
                ->givePermissionTo(...$defaultRole->permissions());
        }
    }

    /**
     * @return HasMany<OrgUser, $this>
     */
    public function orgUsers(): HasMany
    {
        return $this->hasMany(OrgUser::class);
    }

    /**
     * The organization's Owner role (every organization has one).
     */
    public function ownerRole(): Role
    {
        return $this->roles()->where('name', DefaultRole::Owner->value)->sole();
    }

    /**
     * How many members hold the Owner role.
     */
    public function ownerCount(): int
    {
        return $this->orgUsers()->whereHas('roles', fn (Builder $query): Builder => $query->where('name', DefaultRole::Owner->value))->count();
    }

    /**
     * @return HasMany<OrgInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(OrgInvitation::class);
    }

    /**
     * Invitations that have not been accepted and have not expired.
     *
     * @return HasMany<OrgInvitation, $this>
     */
    public function pendingInvitations(): HasMany
    {
        return $this->invitations()->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    /**
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }
}
