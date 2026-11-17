<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * The public pages worth indexing. Everything else is private and kept out of search.
 */
final class SitemapController
{
    public function __invoke(): Response
    {
        $urls = array_map(
            fn (string $route): string => sprintf('  <url><loc>%s</loc></url>', e(route($route))),
            ['home', 'login', 'register'],
        );

        $xml = implode("\n", [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
            ...$urls,
            '</urlset>',
        ])."\n";

        return response($xml, 200, ['Content-Type' => 'application/xml; charset=UTF-8']);
    }
}
