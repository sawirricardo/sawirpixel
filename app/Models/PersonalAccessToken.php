<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

/**
 * @property-read int $id
 * @property string $name
 * @property int|null $organization_id The organization the token acts in.
 * @property list<string> $abilities
 * @property CarbonInterface|null $last_used_at
 * @property CarbonInterface|null $expires_at
 * @property CarbonInterface|null $created_at
 */
final class PersonalAccessToken extends SanctumPersonalAccessToken
{
    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && ! $this->expires_at->isFuture();
    }
}
