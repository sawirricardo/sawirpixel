<?php

declare(strict_types=1);

use App\Models\User;

test('lets a super admin open the horizon dashboard', function (): void {
    config()->set('app.admins', ['admin@example.com']);

    $response = $this->actingAs(User::factory()->create(['email' => 'admin@example.com']))->get('/admin/horizon');

    $response->assertOk();
});

test('forbids the horizon dashboard to everyone else', function (?string $email): void {
    config()->set('app.admins', ['admin@example.com']);
    $request = $email === null ? $this : $this->actingAs(User::factory()->create(['email' => $email]));

    $response = $request->get('/admin/horizon');

    $response->assertForbidden();
})->with([
    'a regular user' => ['member@example.com'],
    'a guest' => [null],
]);

test('does not open the horizon dashboard to everyone in the local environment', function (): void {
    config()->set('app.admins', ['admin@example.com']);
    app()->detectEnvironment(fn (): string => 'local');

    $response = $this->actingAs(User::factory()->create(['email' => 'member@example.com']))->get('/admin/horizon');

    $response->assertForbidden();
});
