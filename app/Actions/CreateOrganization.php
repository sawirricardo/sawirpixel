<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\DefaultRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates an organization, makes the user its Owner, and switches the user to it.
 */
final readonly class CreateOrganization
{
    public function handle(User $user, string $name): Organization
    {
        return DB::transaction(function () use ($user, $name): Organization {
            $organization = Organization::query()->create(['name' => $name]);

            $organization->orgUsers()->create(['user_id' => $user->id])->assignRole(
                $organization->roles()->where('name', DefaultRole::Owner->value)->sole(),
            );

            $user->currentOrganization()->associate($organization)->save();

            return $organization;
        });
    }
}
