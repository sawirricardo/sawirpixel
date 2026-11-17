<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\TokenAbility;
use App\Models\Organization;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Issues and regenerates personal access tokens. The secret exists in plain text only in the return
 * value: the database keeps a hash, so it can be shown once and never again.
 */
final readonly class IssuePersonalAccessToken
{
    /**
     * @param  list<TokenAbility>  $abilities
     * @return array{token: PersonalAccessToken, plainText: string}
     */
    public function handle(User $user, Organization $organization, string $name, array $abilities, ?CarbonInterface $expiresAt): array
    {
        return DB::transaction(function () use ($user, $organization, $name, $abilities, $expiresAt): array {
            $newToken = $user->createToken($name, array_map(fn (TokenAbility $ability): string => $ability->value, $abilities), $expiresAt);
            $token = PersonalAccessToken::query()->whereKey($newToken->accessToken->getKey())->sole();
            $token->organization_id = $organization->id;
            $token->save();

            return ['token' => $token, 'plainText' => $newToken->plainTextToken];
        });
    }

    /**
     * Replace a token's secret, keeping its name, organization and scopes. The old secret stops working
     * immediately.
     *
     * @return array{token: PersonalAccessToken, plainText: string}
     */
    public function regenerate(User $user, PersonalAccessToken $token, ?CarbonInterface $expiresAt): array
    {
        $secret = $user->generateTokenString();

        $token->forceFill([
            'token' => hash('sha256', $secret),
            'expires_at' => $expiresAt,
            'last_used_at' => null,
        ])->save();

        return ['token' => $token, 'plainText' => $token->id.'|'.$secret];
    }
}
