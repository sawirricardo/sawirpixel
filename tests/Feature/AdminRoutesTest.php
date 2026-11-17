<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;

test('guards every application route under admin/ with the operator gate', function (): void {
    $adminRoutes = collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_starts_with($route->uri(), 'admin/') && ! str_starts_with($route->uri(), 'admin/horizon'));

    expect($adminRoutes)->not->toBeEmpty();
    foreach ($adminRoutes as $route) {
        expect($route->gatherMiddleware())->toContain('auth', 'can:accessAdmin');
    }
});

test('serves horizon under admin/', function (): void {
    $horizonRoutes = collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $route): bool => str_contains((string) $route->getName(), 'horizon.'));

    expect($horizonRoutes)->not->toBeEmpty();
    foreach ($horizonRoutes as $route) {
        expect($route->uri())->toStartWith('admin/horizon');
    }
});

test('does not reveal which user ids exist to non-operators', function (): void {
    config()->set('app.admins', ['ops@example.com']);
    $member = User::factory()->create(['email' => 'member@example.com']);

    $this->actingAs($member)->get('/admin/users/'.$member->id)->assertForbidden();
    $this->actingAs($member)->get('/admin/users/999999')->assertForbidden();
});
