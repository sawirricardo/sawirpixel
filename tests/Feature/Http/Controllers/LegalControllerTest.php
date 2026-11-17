<?php

declare(strict_types=1);

use App\Http\Controllers\LegalController;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

test('shows the terms and the privacy policy to anyone, naming who runs the service', function (string $route, string $component): void {
    config()->set('app.name', 'Sawirpixel');
    config()->set('app.legal.operator', 'Sawir Studio');
    config()->set('app.legal.contact_email', 'legal@example.com');

    $this->get(route($route))->assertOk()->assertInertia(fn (Assert $page): Assert => $page
        ->component($component)
        ->where('appName', 'Sawirpixel')
        ->where('operator', 'Sawir Studio')
        ->where('contactEmail', 'legal@example.com')
        ->where('updatedAt', '2026-11-17'));
})->with([
    'terms' => ['terms', 'legal/Terms'],
    'privacy' => ['privacy', 'legal/Privacy'],
]);

test('leaves out the contact address until one is configured', function (): void {
    config()->set('app.legal.contact_email', '');

    $this->get(route('privacy'))->assertInertia(fn (Assert $page): Assert => $page->where('contactEmail', null));
});

test('only serves the two documents', function (): void {
    $this->get('/legal/anything')->assertNotFound();
    expect(fn () => resolve(LegalController::class)->show('cookies'))
        ->toThrow(NotFoundHttpException::class);
});
