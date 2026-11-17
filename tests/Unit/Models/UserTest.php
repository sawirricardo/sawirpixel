<?php

declare(strict_types=1);

use App\Models\User;

test('to array', function (): void {
    $user = User::factory()->create()->refresh();

    expect(array_keys($user->toArray()))
        ->toBe([
            'id',
            'name',
            'email',
            'email_verified_at',
            'created_at',
            'updated_at',
            'current_organization_id',
        ]);
});

test('is a super admin when the email is listed in APP_ADMINS regardless of case', function (): void {
    config()->set('app.admins', ['admin@example.com', 'other@example.com']);
    $user = User::factory()->create(['email' => 'Admin@Example.com']);

    expect($user->isSuperAdmin())->toBeTrue();
});

test('is not a super admin when the email is not listed in APP_ADMINS', function (): void {
    config()->set('app.admins', ['admin@example.com']);
    $user = User::factory()->create(['email' => 'member@example.com']);

    expect($user->isSuperAdmin())->toBeFalse();
});
