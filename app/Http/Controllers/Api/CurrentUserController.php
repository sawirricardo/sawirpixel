<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;

final class CurrentUserController
{
    /**
     * Who you are.
     *
     * The user behind the token, the organization it acts in, and its scopes. Any scope can call this.
     */
    public function __invoke(#[CurrentUser] User $user): UserResource
    {
        return new UserResource($user);
    }
}
