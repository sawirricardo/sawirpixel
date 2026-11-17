<?php

declare(strict_types=1);

use App\Models\Organization;
use App\Models\Role;

test('belongs to the organization that defines it', function (): void {
    $organization = Organization::factory()->create();
    $role = Role::factory()->for($organization)->create();
    $otherRole = Role::factory()->create();

    expect($organization->roles()->pluck('id')->all())->toContain($role->id)->not->toContain($otherRole->id);
    expect($role->organization()->is($organization))->toBeTrue();
});
