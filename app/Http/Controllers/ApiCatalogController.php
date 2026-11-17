<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * The API catalog (RFC 9727) at /.well-known/api-catalog: where agents find the API's machine-readable
 * description and its human-readable docs.
 */
final class ApiCatalogController
{
    public const string PROFILE = 'https://www.rfc-editor.org/info/rfc9727';

    public function __invoke(): JsonResponse
    {
        return response()->json([
            'linkset' => [[
                'anchor' => url('/api'),
                'service-desc' => [['href' => url('/docs/api.json'), 'type' => 'application/json']],
                'service-doc' => [['href' => url('/docs/api'), 'type' => 'text/html']],
            ]],
        ], 200, ['Content-Type' => sprintf('application/linkset+json; profile="%s"', self::PROFILE)], JSON_UNESCAPED_SLASHES);
    }
}
