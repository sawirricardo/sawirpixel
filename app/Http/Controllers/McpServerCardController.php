<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mcp\Servers\SawirpixelServer;
use Illuminate\Http\JsonResponse;
use Laravel\Mcp\Enums\ProtocolVersion;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;

/**
 * The MCP server card (SEP-1649) at /.well-known/mcp/server-card.json: what the MCP server is, where it
 * lives, how to authenticate, and its tools with their input schemas, so agents can decide to use it
 * before connecting. Built from the server and tool definitions themselves, so it can't drift.
 */
final class McpServerCardController
{
    public function __invoke(): JsonResponse
    {
        $server = new ReflectionClass(SawirpixelServer::class);
        $attribute = fn (string $class): ?string => ($server->getAttributes($class)[0] ?? null)?->newInstance()->value ?? null;

        return response()->json([
            'version' => '1.0',
            'protocolVersion' => ProtocolVersion::LATEST->value,
            'serverInfo' => [
                'name' => $attribute(Name::class),
                'title' => config('app.name'),
                'version' => $attribute(Version::class),
            ],
            'description' => 'Generate images from text prompts, paid for with prepaid credits: estimate the price, generate, and poll for the result.',
            'documentationUrl' => url('/docs/api'),
            'transport' => ['type' => 'streamable-http', 'endpoint' => url('/mcp')],
            'capabilities' => ['tools' => ['listChanged' => false]],
            'authentication' => [
                'required' => true,
                'schemes' => ['bearer'],
                'instructions' => 'Create a personal access token in your profile at '.url('/settings/profile').' and send it as Authorization: Bearer <token>.',
            ],
            'instructions' => $attribute(Instructions::class),
            // The server's own tool list, so the card always matches what /mcp offers.
            'tools' => array_map(
                fn (Tool $tool): array => array_intersect_key($tool->toArray(), array_flip(['name', 'description', 'inputSchema', 'annotations'])),
                $this->tools($server),
            ),
        ], 200, [], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  ReflectionClass<SawirpixelServer>  $server
     * @return list<Tool>
     */
    private function tools(ReflectionClass $server): array
    {
        $classes = $server->getDefaultProperties()['tools'] ?? [];
        $tools = [];

        foreach (is_array($classes) ? $classes : [] as $class) {
            $tool = is_string($class) ? resolve($class) : null;

            if ($tool instanceof Tool) {
                $tools[] = $tool;
            }
        }

        return $tools;
    }
}
