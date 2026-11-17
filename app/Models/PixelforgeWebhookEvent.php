<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PixelforgeWebhookEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A received Pixelforge webhook event. The unique event id is what guarantees each event is processed once.
 *
 * @property-read int $id
 * @property string $event_id
 * @property string $type
 * @property string|null $pixelforge_generation_id
 * @property int|null $generation_id
 * @property array<array-key, mixed> $payload
 */
final class PixelforgeWebhookEvent extends Model
{
    /** @use HasFactory<PixelforgeWebhookEventFactory> */
    use HasFactory;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'payload' => 'array',
    ];
}
