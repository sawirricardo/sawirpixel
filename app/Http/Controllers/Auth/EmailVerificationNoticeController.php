<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Check your inbox": where the verified middleware sends unverified users.
 */
final class EmailVerificationNoticeController
{
    public function __invoke(#[CurrentUser] User $user): Response|RedirectResponse
    {
        if ($user->hasVerifiedEmail()) {
            return redirect()->intended(route('home'));
        }

        return Inertia::render('auth/VerifyEmail', ['email' => $user->email]);
    }
}
