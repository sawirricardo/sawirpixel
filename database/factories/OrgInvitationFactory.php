<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DefaultRole;
use App\Models\Organization;
use App\Models\OrgInvitation;
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
            'invited_by_id' => null,
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => OrgInvitation::hashToken(Str::random(40)),
            'expires_at' => now()->addDays(OrgInvitation::EXPIRES_AFTER_DAYS),
            'accepted_at' => null,
        ];
    }

    /**
     * Invitations get the organization's Member role unless a test attaches others.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (OrgInvitation $invitation): void {
            if ($invitation->roles()->doesntExist()) {
                $invitation->assignRole($invitation->organization()->sole()->roles()->where('name', DefaultRole::Member->value)->sole());
            }
        });
    }

    /**
     * @param  list<DefaultRole>  $roles
     */
    public function withRoles(array $roles): static
    {
        return $this->afterCreating(function (OrgInvitation $invitation) use ($roles): void {
            $invitation->roles()->detach();
            $invitation->assignRole(...$invitation->organization()->sole()->roles()
                ->whereIn('name', array_map(fn (DefaultRole $role): string => $role->value, $roles))
                ->get()
                ->all());
        });
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
