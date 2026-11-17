<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Requests\UpdateProfileRequest;
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
        ]);
    }

    /**
     * Changing the email address marks it unverified again.
     */
    public function update(UpdateProfileRequest $request, #[CurrentUser] User $user): RedirectResponse
    {
        $user->fill([
            'name' => $request->string('name')->value(),
            'email' => $request->string('email')->value(),
        ]);

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        Inertia::flash('success', 'Profile updated.');

        return back();
    }
}
