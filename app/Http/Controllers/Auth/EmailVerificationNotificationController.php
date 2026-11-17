<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Sends the verification link again.
 */
final class EmailVerificationNotificationController
{
    public function store(#[CurrentUser] User $user): RedirectResponse
    {
        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('home'));
        }

        $user->sendEmailVerificationNotification();

        Inertia::flash('success', sprintf('We sent a new verification link to %s.', $user->email));

        return back();
    }
}
