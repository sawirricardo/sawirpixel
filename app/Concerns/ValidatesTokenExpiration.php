<?php

declare(strict_types=1);

namespace App\Concerns;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

/**
 * A token's lifetime, picked like GitHub's: a number of days, or no expiration.
 */
trait ValidatesTokenExpiration
{
    /** @var list<int> */
    public const array EXPIRATION_DAYS = [7, 30, 60, 90];

    public const string NO_EXPIRATION = 'none';

    /**
     * @return list<string|In>
     */
    public function expirationRules(): array
    {
        return ['required', Rule::in([...array_map(strval(...), self::EXPIRATION_DAYS), self::NO_EXPIRATION])];
    }

    public function expiresAt(): ?CarbonImmutable
    {
        $expiration = $this->string('expiration')->value();

        return $expiration === self::NO_EXPIRATION ? null : CarbonImmutable::now()->addDays((int) $expiration);
    }
}
