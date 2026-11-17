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
}
