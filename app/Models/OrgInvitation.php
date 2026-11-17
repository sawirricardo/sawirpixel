<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrgInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class OrgInvitation extends Model
{
    /** @use HasFactory<OrgInvitationFactory> */
    use HasFactory;
}
