<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Requests\ForgotPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

final class PasswordResetLinkController
{
    public function create(): Response
    {
        return Inertia::render('auth/ForgotPassword');
    }

    /**
     * Always answers the same way, whether or not the address has an account, so the form can't be
     * used to find out who is registered.
     */
    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        Password::sendResetLink(['email' => $request->string('email')->value()]);

        Inertia::flash('success', 'If that address has an account, a password reset link is on its way.');

        return back();
    }
}
