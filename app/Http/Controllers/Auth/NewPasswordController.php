<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class NewPasswordController
{
    public function create(Request $request, string $token): Response
    {
        return Inertia::render('auth/ResetPassword', [
            'token' => $token,
            'email' => $request->string('email')->value(),
        ]);
    }

    /**
     * @throws ValidationException when the link is invalid or expired
     */
    public function store(ResetPasswordRequest $request): RedirectResponse
    {
        $status = Password::reset(
            [
                'email' => $request->string('email')->value(),
                'password' => $request->string('password')->value(),
                'password_confirmation' => $request->string('password_confirmation')->value(),
                'token' => $request->string('token')->value(),
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => trans(is_string($status) ? $status : 'passwords.token')]);
        }

        Inertia::flash('success', 'Your password has been reset. Log in with the new one.');

        return to_route('login');
    }
}
