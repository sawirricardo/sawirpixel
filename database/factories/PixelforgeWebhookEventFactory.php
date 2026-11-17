<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PixelforgeWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PixelforgeWebhookEvent>
 */
final class PixelforgeWebhookEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event_id' => 'evt_'.Str::random(26),
            'type' => 'ping',
            'payload' => ['object' => 'event', 'type' => 'ping'],
        ];
    }
}
