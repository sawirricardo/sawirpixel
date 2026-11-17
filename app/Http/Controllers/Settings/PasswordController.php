<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Requests\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class PasswordController
{
    public function update(UpdatePasswordRequest $request, #[CurrentUser] User $user): RedirectResponse
    {
        $user->update(['password' => $request->string('password')->value()]);

        Inertia::flash('success', 'Password changed.');

        return back();
    }
}
