<?php

declare(strict_types=1);

use App\Http\Controllers\LegalController;
use App\Services\Agents\MarkdownPages;
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
        ->where('updatedAt', '2026-11-17')
        ->where('html', fn (string $html): bool => str_contains($html, '<h2>')
            && str_contains($html, 'Sawir Studio')
            && str_contains($html, 'href="mailto:legal@example.com"')));
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
    expect(fn () => resolve(LegalController::class)->show('cookies', resolve(MarkdownPages::class)))
        ->toThrow(NotFoundHttpException::class);
});

test('serves the same documents as Markdown to agents that ask for it', function (string $route, string $heading): void {
    config()->set('app.legal.contact_email', 'legal@example.com');

    $response = $this->get(route($route), ['Accept' => 'text/markdown']);

    $response->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=utf-8')->assertHeaderContains('Vary', 'Accept');
    expect($response->getContent())
        ->toStartWith('# '.$heading."\n\n")
        ->toContain('## Contact')
        ->toContain('[legal@example.com](mailto:legal@example.com)')
        ->not->toContain('{app}');
})->with([
    'terms' => ['terms', 'Terms of service'],
    'privacy' => ['privacy', 'Privacy policy'],
]);
