<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasRoles;
use Database\Factories\OrgUserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's membership in an organization. Roles and permissions are granted per membership.
 *
 * @property-read int $id
 * @property int $organization_id
 * @property int $user_id
 */
final class OrgUser extends Model
{
    /** @use HasFactory<OrgUserFactory> */
    use HasFactory;

    use HasRoles;

    /**
     * Only roles defined by the membership's own organization may be assigned.
     */
    public function canBeAssignedRole(Role $role): bool
    {
        return $role->organization_id === $this->organization_id;
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
