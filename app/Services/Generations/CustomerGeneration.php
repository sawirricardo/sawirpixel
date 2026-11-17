<?php

declare(strict_types=1);

namespace App\Services\Generations;

use App\Models\Generation;
use App\Services\Pricing\Money;

/**
 * A generation as customers see it, wherever they see it (the API and the MCP server): no provider
 * costs, token usage or provider ids, and our own wording for failures.
 */
final class CustomerGeneration
{
    /**
     * @return array<string, mixed>
     */
    public static function attributes(Generation $generation): array
    {
        return [
            'prompt' => $generation->prompt,
            'size' => $generation->size->value,
            'quality' => $generation->quality->value,
            'status' => $generation->status->value,
            // A follow-up's first reference is its parent's image; list only the ones its author added.
            'reference_images' => $generation->parent_id === null ? $generation->reference_images : array_slice($generation->reference_images, 1),
            'parent_id' => $generation->parent_id,
            'thread_id' => $generation->thread_id,
            'estimated_charge' => ['min' => Money::toArray($generation->estimated_charge_min), 'max' => Money::toArray($generation->estimated_charge_max)],
            'charge' => $generation->charge === null ? null : Money::toArray($generation->charge),
            'image_url' => $generation->image_url,
            'image_url_expires_at' => $generation->image_url_expires_at?->toIso8601String(),
            'error_message' => $generation->error_message,
            'created_at' => $generation->created_at?->toIso8601String(),
            'completed_at' => $generation->completed_at?->toIso8601String(),
        ];
    }
}
