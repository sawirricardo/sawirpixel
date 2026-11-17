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

test('names AI crawlers and says how content may be used, with Content Signals', function (): void {
    $robots = (string) $this->get('/robots.txt')->getContent();

    expect($robots)
        ->toContain("User-agent: GPTBot\n")
        ->toContain("User-agent: ClaudeBot\n")
        ->toContain("User-agent: Google-Extended\n")
        ->toContain("Content-Signal: search=yes, ai-input=yes, ai-train=no\n");
    // Every group carries the same rules: the AI crawlers' group keeps private paths out too.
    expect(mb_substr_count($robots, "Disallow: /settings\n"))->toBe(2);
    expect(mb_substr_count($robots, 'Content-Signal:'))->toBe(2);
});

test('publishes an API catalog pointing to the API description and docs', function (): void {
    $response = $this->get('/.well-known/api-catalog');

    $response->assertOk()->assertHeader('Content-Type', 'application/linkset+json; profile="https://www.rfc-editor.org/info/rfc9727"');
    expect($response->json('linkset.0'))->toBe([
        'anchor' => url('/api'),
        'service-desc' => [['href' => url('/docs/api.json'), 'type' => 'application/json']],
        'service-doc' => [['href' => url('/docs/api'), 'type' => 'text/html']],
    ]);
});

test('advertises discovery links on public pages', function (string $route): void {
    $link = (string) $this->get(route($route))->headers->get('Link');

    expect($link)
        ->toContain('</.well-known/api-catalog>; rel="api-catalog"')
        ->toContain('</docs/api.json>; rel="service-desc"')
        ->toContain('</sitemap.xml>; rel="sitemap"');
})->with(['home', 'terms', 'privacy']);

test('serves the API docs to anyone, including agents without an account', function (): void {
    $this->get('/docs/api.json')->assertOk()->assertJsonPath('info.title', config('app.name'));
});

test('lists only the public pages in the sitemap', function (): void {
    $response = $this->get('/sitemap.xml');

    $response->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
    $xml = simplexml_load_string((string) $response->getContent());
    expect(array_map(fn (SimpleXMLElement $url): string => (string) $url->loc, iterator_to_array($xml->url, false)))
        ->toBe([route('home'), route('login'), route('register'), route('terms'), route('privacy')]);
});

test('describes the service as Markdown to agents, and as HTML to browsers', function (): void {
    config()->set('app.name', 'Sawirpixel');

    $markdown = $this->get(route('home'), ['Accept' => 'text/markdown, text/html;q=0.5']);
    $browser = $this->get(route('home'), ['Accept' => 'text/html,application/xhtml+xml,*/*;q=0.8']);

    $markdown->assertOk()->assertHeader('Content-Type', 'text/markdown; charset=utf-8')->assertHeaderContains('Vary', 'Accept');
    expect($markdown->getContent())->toStartWith("# Sawirpixel\n\n")->toContain('/.well-known/api-catalog');
    $browser->assertOk()->assertHeaderContains('Vary', 'Accept');
    expect($browser->headers->get('Content-Type'))->toStartWith('text/html');
});

test('never answers an Inertia visit with Markdown', function (): void {
    $response = $this->get(route('terms'), ['Accept' => 'text/markdown', 'X-Inertia' => 'true']);

    expect((string) $response->headers->get('Content-Type'))->not->toContain('markdown');
});
