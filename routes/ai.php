<?php

declare(strict_types=1);

use App\Mcp\Servers\SawirpixelServer;
use Laravel\Mcp\Facades\Mcp;

/**
 * The MCP server, authenticated with personal access tokens like the API.
 */
Mcp::web('/mcp', SawirpixelServer::class)->middleware(['auth:sanctum', 'throttle:60,1'])->name('mcp');
