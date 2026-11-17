<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles every organization receives when it is created.
 */
enum DefaultRole: string
{
    case Owner = 'Owner';
    case Admin = 'Admin';
    case Member = 'Member';

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Owner => Permission::cases(),
            self::Admin => array_values(array_filter(
                Permission::cases(),
                fn (Permission $permission): bool => $permission !== Permission::DeleteOrganization,
            )),
            self::Member => [Permission::ViewMembers, Permission::CreateGenerations],
        };
    }
}
