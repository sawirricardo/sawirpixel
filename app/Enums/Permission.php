<?php

declare(strict_types=1);

namespace App\Enums;

enum Permission: string
{
    case UpdateOrganization = 'organization.update';
    case DeleteOrganization = 'organization.delete';
    case ViewMembers = 'members.view';
    case InviteMembers = 'members.invite';
    case RemoveMembers = 'members.remove';
    case ManageRoles = 'roles.manage';
    case CreateGenerations = 'generations.create';
    case BuyCredits = 'credits.buy';

    public function label(): string
    {
        return match ($this) {
            self::UpdateOrganization => 'Update the organization',
            self::DeleteOrganization => 'Delete the organization',
            self::ViewMembers => 'View members',
            self::InviteMembers => 'Invite members',
            self::RemoveMembers => 'Remove members',
            self::ManageRoles => 'Change member roles',
            self::CreateGenerations => 'Generate images',
            self::BuyCredits => 'Buy credits',
        };
    }
}
