<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\EstimatePriceTool;
use App\Mcp\Tools\GenerateImageTool;
use App\Mcp\Tools\GetCreditBalanceTool;
use App\Mcp\Tools\GetGenerationTool;
use App\Mcp\Tools\ListGenerationsTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

/**
 * Sawirpixel over the Model Context Protocol, at /mcp. Authenticated with the same personal access
 * tokens as the API, with the same scopes, organization binding and rules.
 */
#[Name('Sawirpixel')]
#[Version('1.0.0')]
#[Instructions(<<<'MARKDOWN'
    Sawirpixel turns text prompts into images, paid for with the organization's prepaid credits.
    Call estimate_price before generate_image to see the cost and whether it's affordable. Generating is
    asynchronous: poll get_generation every few seconds until status is succeeded or failed. Image links
    expire after 24 hours, so download anything worth keeping. Failed images cost nothing.
    MARKDOWN)]
final class SawirpixelServer extends Server
{
    /** @var list<class-string<Server\Tool>> */
    protected array $tools = [
        EstimatePriceTool::class,
        GenerateImageTool::class,
        GetGenerationTool::class,
        ListGenerationsTool::class,
        GetCreditBalanceTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
