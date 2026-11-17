<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * robots.txt, generated so the sitemap line carries the real domain. AI crawlers are named explicitly
 * and get the same access as everyone else, with Content Signals saying how the content may be used:
 * indexed for search and quoted in answers, but not used to train models.
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
        '/notifications',
        '/organization',
        '/organizations',
        '/reset-password',
        '/settings',
        '/webhooks',
    ];

    /** Crawlers that fetch content for AI search, answers or training. */
    public const array AI_CRAWLERS = [
        'GPTBot',
        'OAI-SearchBot',
        'ChatGPT-User',
        'ClaudeBot',
        'Claude-SearchBot',
        'Claude-User',
        'Google-Extended',
        'PerplexityBot',
        'Perplexity-User',
        'Applebot-Extended',
        'Meta-ExternalAgent',
        'CCBot',
    ];

    public const string CONTENT_SIGNAL = 'search=yes, ai-input=yes, ai-train=no';

    public function __invoke(): Response
    {
        $rules = [
            'Content-Signal: '.self::CONTENT_SIGNAL,
            ...array_map(fn (string $path): string => 'Disallow: '.$path, self::DISALLOWED),
            'Allow: /',
        ];

        $lines = [
            "# Content Signals: search=yes (index it), ai-input=yes (quote it in AI answers), ai-train=no (don't train on it).",
            '# The API is described at /.well-known/api-catalog, and the MCP server at /.well-known/mcp/server-card.json.',
            '',
            'User-agent: *',
            ...$rules,
            '',
            ...array_map(fn (string $agent): string => 'User-agent: '.$agent, self::AI_CRAWLERS),
            ...$rules,
            '',
            'Sitemap: '.route('sitemap'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
