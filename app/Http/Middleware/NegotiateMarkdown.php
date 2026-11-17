<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Agents\MarkdownPages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves a public page as Markdown to clients that prefer it (Accept: text/markdown), the way agents ask
 * for content. Browsers, which prefer HTML, get the normal page. Either way the response varies on
 * Accept, so caches keep the two apart.
 */
final readonly class NegotiateMarkdown
{
    public function __construct(private MarkdownPages $pages) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next, string $page): Response
    {
        if (! $request->hasHeader('X-Inertia') && $request->prefers(['text/html', 'text/markdown']) === 'text/markdown') {
            $response = response($this->pages->markdown($page), 200, ['Content-Type' => 'text/markdown; charset=utf-8']);
        } else {
            $response = $next($request);
        }

        $response->headers->set('Vary', 'Accept', false);

        return $response;
    }
}
