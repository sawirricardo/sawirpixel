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
