<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Actions\IssuePersonalAccessToken;
use App\Http\Requests\CreatePersonalAccessTokenRequest;
use App\Http\Requests\UpdatePersonalAccessTokenRequest;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Personal access tokens, managed from the profile page with a logged-in session only: a token can't
 * create, change or reveal tokens, so it can never widen its own access.
 */
final class PersonalAccessTokenController
{
    public function store(CreatePersonalAccessTokenRequest $request, #[CurrentUser] User $user, IssuePersonalAccessToken $issue): RedirectResponse
    {
        $issued = $issue->handle($user, $request->organization(), $request->string('name')->value(), $request->abilities(), $request->expiresAt());

        Inertia::flash('newToken', ['id' => $issued['token']->id, 'plainText' => $issued['plainText']]);

        return back();
    }

    public function update(UpdatePersonalAccessTokenRequest $request, #[CurrentUser] User $user, string $token): RedirectResponse
    {
        $this->find($user, $token)->forceFill([
            'name' => $request->string('name')->value(),
            'abilities' => $request->abilityValues(),
        ])->save();

        Inertia::flash('success', 'Token updated.');

        return back();
    }

    public function destroy(#[CurrentUser] User $user, string $token): RedirectResponse
    {
        $token = $this->find($user, $token);
        $token->delete();

        Inertia::flash('success', sprintf('Deleted the token "%s". Anything using it can no longer access the API.', $token->name));

        return back();
    }

    /**
     * Only the user's own tokens: anyone else's id is simply not found.
     */
    private function find(User $user, string $id): PersonalAccessToken
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->id)
            ->findOrFail($id);
    }
}
