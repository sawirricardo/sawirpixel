<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasRoles;
use Carbon\CarbonInterface;
use Database\Factories\OrgInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An invitation to join an organization. Like a membership it holds roles and direct permissions, which
 * are copied onto the membership when it is accepted. Only a hash of the token is stored; the token
 * itself exists only in the emailed link.
 *
 * @property-read int $id
 * @property int $organization_id
 * @property int|null $invited_by_id
 * @property string $email
 * @property string $token_hash
 * @property CarbonInterface $expires_at
 * @property CarbonInterface|null $accepted_at
 */
final class OrgInvitation extends Model
{
    /** @use HasFactory<OrgInvitationFactory> */
    use HasFactory;

    use HasRoles;

    public const int EXPIRES_AFTER_DAYS = 7;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'expires_at' => 'datetime',
        'accepted_at' => 'datetime',
    ];

    /**
     * Morph pivots don't cascade, so an invitation's roles and permissions go with it.
     */
    #[Boot]
    public static function detachRolesAndPermissionsOnDelete(): void
    {
        self::deleting(function (OrgInvitation $invitation): void {
            $invitation->roles()->detach();
            $invitation->permissions()->detach();
        });
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Only roles defined by the invitation's own organization may be attached.
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
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_id');
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at->isFuture();
    }
}
