<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
final class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use Notifiable;

    /**
     * Mirrors the column default so a freshly created user has no current organization.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'current_organization_id' => null,
    ];

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    /**
     * Determine whether the user's email is listed in APP_ADMINS.
     */
    public function isSuperAdmin(): bool
    {
        return in_array(mb_strtolower($this->email), config()->array('app.admins'), true);
    }

    /**
     * The organization the user is currently working in.
     *
     * @return BelongsTo<Organization, $this>
     */
    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    /**
     * The user's membership in the given organization, if any.
     */
    public function membershipIn(Organization $organization): ?OrgUser
    {
        return $this->orgUsers()->whereBelongsTo($organization)->first();
    }

    /**
     * Every generation the user has submitted, in any organization.
     *
     * @return HasMany<Generation, $this>
     */
    public function generations(): HasMany
    {
        return $this->hasMany(Generation::class);
    }

    /**
     * The user's organization memberships.
     *
     * @return HasMany<OrgUser, $this>
     */
    public function orgUsers(): HasMany
    {
        return $this->hasMany(OrgUser::class);
    }
}
