<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DefaultRole;
use App\Models\Organization;
use App\Models\OrgInvitation;
use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OrgInvitation>
 */
final class OrgInvitationFactory extends Factory
{
    /**
     * Define the model's default state. Pass a known token with withToken() when a test needs the link.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'role_id' => function (array $attributes): int {
                $memberRoleId = Role::query()
                    ->where('organization_id', $attributes['organization_id'])
                    ->where('name', DefaultRole::Member->value)
                    ->value('id');

                return is_int($memberRoleId) ? $memberRoleId : Role::factory()->create(['organization_id' => $attributes['organization_id']])->id;
            },
            'invited_by_id' => null,
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => OrgInvitation::hashToken(Str::random(40)),
            'expires_at' => now()->addDays(OrgInvitation::EXPIRES_AFTER_DAYS),
            'accepted_at' => null,
        ];
    }

    public function withToken(string $token): static
    {
        return $this->state(fn (): array => ['token_hash' => OrgInvitation::hashToken($token)]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function accepted(): static
    {
        return $this->state(fn (): array => ['accepted_at' => now()]);
    }
}
