<?php

declare(strict_types=1);

use App\Models\User;

test('describes public pages for search results and link previews', function (): void {
    config()->set('app.name', 'Sawirpixel');
    config()->set('app.description', 'Turn a prompt into an image.');

    $response = $this->get(route('home'));

    $response->assertOk()
        ->assertSee('<meta name="description" content="Turn a prompt into an image.">', escape: false)
        ->assertSee('<meta property="og:title" content="Sawirpixel">', escape: false)
        ->assertSee('<link rel="canonical" href="'.route('home').'">', escape: false)
        ->assertSee('<title>Sawirpixel</title>', escape: false)
        ->assertHeaderMissing('X-Robots-Tag');
});

test('keeps private pages out of search results', function (string $route, array $parameters, bool $loggedIn): void {
    $request = $loggedIn ? $this->actingAs(organizationOwner()) : $this;

    $request->get(route($route, $parameters))->assertHeader('X-Robots-Tag', 'noindex, nofollow');
})->with([
    'generations' => ['generations.index', [], true],
    'credits' => ['credits.index', [], true],
    'profile' => ['profile.edit', [], true],
    'organization settings' => ['organization.settings', [], true],
    'invitation link' => ['invitations.show', ['token' => 'abc'], false],
    'password reset link' => ['password.reset', ['token' => 'abc'], false],
]);

test('keeps the operators area out of search results', function (): void {
    config()->set('app.admins', ['ops@example.com']);

    $this->actingAs(User::factory()->create(['email' => 'ops@example.com']))
        ->get(route('admin.users.index'))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

test('tells crawlers which paths to skip and where the sitemap is', function (): void {
    $response = $this->get('/robots.txt');

    $response->assertOk()->assertHeader('Content-Type', 'text/plain; charset=UTF-8');
    expect($response->getContent())
        ->toContain("User-agent: *\n")
        ->toContain("Disallow: /admin\n")
        ->toContain("Disallow: /generations\n")
        ->toContain('Sitemap: '.route('sitemap'));
});

test('lists only the public pages in the sitemap', function (): void {
    $response = $this->get('/sitemap.xml');

    $response->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    $xml = simplexml_load_string((string) $response->getContent());
    expect(array_map(fn (SimpleXMLElement $url): string => (string) $url->loc, iterator_to_array($xml->url, false)))
        ->toBe([route('home'), route('login'), route('register')]);
});
