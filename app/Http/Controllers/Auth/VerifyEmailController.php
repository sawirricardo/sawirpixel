<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * The link in the verification email. The request checks it's signed and belongs to the logged-in user.
 */
final class VerifyEmailController
{
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        $request->fulfill();

        Inertia::flash('success', 'Your email is verified.');

        return redirect()->intended(route('home'));
    }
}
