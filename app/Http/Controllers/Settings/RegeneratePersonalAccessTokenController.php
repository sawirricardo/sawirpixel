<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\IssuePersonalAccessToken;
use App\Http\Requests\RegeneratePersonalAccessTokenRequest;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Replace a token's secret (say it leaked, or expired) without recreating its name and scopes.
 */
final class RegeneratePersonalAccessTokenController
{
    public function __invoke(RegeneratePersonalAccessTokenRequest $request, #[CurrentUser] User $user, string $token, IssuePersonalAccessToken $issue): RedirectResponse
    {
        $token = PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->findOrFail($token);

        $issued = $issue->regenerate($user, $token, $request->expiresAt());

        Inertia::flash('newToken', ['id' => $issued['token']->id, 'plainText' => $issued['plainText']]);

        return back();
    }
}
