<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Resources\CreditBalanceResource;
use App\Models\User;
use App\Services\Api\ApiOrganization;
use Illuminate\Container\Attributes\CurrentUser;

final class CreditController
{
    /**
     * Show the credit balance.
     *
     * The organization's balance, and how much of it is set aside for images still in progress.
     * Requires `credits:read`.
     */
    public function __invoke(#[CurrentUser] User $user): CreditBalanceResource
    {
        return new CreditBalanceResource(ApiOrganization::for($user));
    }
}
