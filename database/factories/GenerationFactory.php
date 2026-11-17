<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;
use App\Models\Generation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
final class GenerationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'user_id' => User::factory(),
            'status' => GenerationStatus::Pending,
            'pixelforge_id' => null,
            'idempotency_key' => (string) Str::uuid(),
            'prompt' => fake()->sentence(),
            'size' => ImageSize::Square,
            'quality' => ImageQuality::Medium,
            'reference_images' => [],
            'estimated_charge_min' => '0.06',
            'estimated_charge_max' => '0.09',
        ];
    }

    /**
     * Indicate that Pixelforge has accepted the generation.
     */
    public function queued(): static
    {
        return $this->state(fn (): array => [
            'status' => GenerationStatus::Queued,
            'pixelforge_id' => 'gen_'.Str::random(26),
            'submitted_at' => now(),
        ]);
    }
}
