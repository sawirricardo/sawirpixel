<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds Link headers (RFC 8288) to public pages, so agents can find the API catalog, the API's
 * description and docs, the MCP server card and the sitemap without guessing paths.
 */
final class AdvertiseDiscovery
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Link', implode(', ', [
            '</.well-known/api-catalog>; rel="api-catalog"',
            '</docs/api.json>; rel="service-desc"; type="application/json"',
            '</docs/api>; rel="service-doc"; type="text/html"',
            '</.well-known/mcp/server-card.json>; rel="mcp-server-card"; type="application/json"',
            '</sitemap.xml>; rel="sitemap"; type="application/xml"',
        ]), false);

        return $response;
    }
}
