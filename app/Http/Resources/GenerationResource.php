<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Generation;
use App\Services\Generations\CustomerGeneration;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * A generation in the API, as JSON:API. Its fields are CustomerGeneration's, shared with MCP.
 *
 * @property Generation $resource
 */
final class GenerationResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'generations';
    }

    public function toId(Request $request): string
    {
        return (string) $this->resource->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return CustomerGeneration::attributes($this->resource);
    }
}
