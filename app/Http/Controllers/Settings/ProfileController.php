<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Enums\TokenAbility;
use App\Http\Requests\CreatePersonalAccessTokenRequest;
use App\Http\Requests\UpdateProfileRequest;
use App\Models\Organization;
use App\Models\OrgUser;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

final class ProfileController
{
    public function edit(#[CurrentUser] User $user): Response
    {
        return Inertia::render('settings/Profile', [
            'profile' => [
                'name' => $user->name,
                'email' => $user->email,
                'email_verified' => $user->email_verified_at !== null,
            ],
            'tokens' => PersonalAccessToken::query()
                ->where('tokenable_type', $user->getMorphClass())
                ->where('tokenable_id', $user->id)
                ->with('organization:id,name')
                ->latest('id')
                ->get()
                ->map(fn (PersonalAccessToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'abilities' => $token->abilities,
                    'organization' => $token->organization->name ?? null,
                    'last_used_at' => $token->last_used_at?->toIso8601String(),
                    'expires_at' => $token->expires_at?->toIso8601String(),
                    'expired' => $token->isExpired(),
                    'created_at' => $token->created_at?->toIso8601String(),
                ]),
            'tokenOptions' => [
                'abilities' => array_map(fn (TokenAbility $ability): array => ['value' => $ability->value, 'description' => $ability->description()], TokenAbility::cases()),
                'organizations' => $user->orgUsers()
                    ->with('organization:id,name')
                    ->get()
                    ->filter(fn (OrgUser $membership): bool => $membership->organization instanceof Organization)
                    ->map(fn (OrgUser $membership): array => ['id' => $membership->organization_id, 'name' => $membership->organization->name ?? ''])
                    ->sortBy('name')
                    ->values()
                    ->all(),
                'currentOrganizationId' => $user->current_organization_id,
                'expirationDays' => CreatePersonalAccessTokenRequest::EXPIRATION_DAYS,
            ],
        ]);
    }

    /**
     * Changing the email address marks it unverified and sends a verification link to the new one.
     */
    public function update(UpdateProfileRequest $request, #[CurrentUser] User $user): RedirectResponse
    {
        $user->fill([
            'name' => $request->string('name')->value(),
            'email' => $request->string('email')->value(),
        ]);

        $emailChanged = $user->isDirty('email');

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        if ($emailChanged) {
            $user->sendEmailVerificationNotification();
        }

        Inertia::flash('success', $emailChanged
            ? sprintf('Profile updated. We sent a verification link to %s.', $user->email)
            : 'Profile updated.');

        return back();
    }
}
