<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * robots.txt, generated so the sitemap line carries the real domain.
 */
final class RobotsController
{
    /** Paths that are private or only reachable through emailed links. */
    public const array DISALLOWED = [
        '/admin',
        '/credits',
        '/email',
        '/generations',
        '/invitations',
        '/organization',
        '/organizations',
        '/reset-password',
        '/settings',
        '/webhooks',
    ];

    public function __invoke(): Response
    {
        $lines = ['User-agent: *', ...array_map(fn (string $path): string => 'Disallow: '.$path, self::DISALLOWED), '', 'Sitemap: '.route('sitemap')];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
