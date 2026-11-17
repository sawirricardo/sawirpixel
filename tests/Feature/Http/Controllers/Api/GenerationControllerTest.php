<?php

declare(strict_types=1);

use App\Enums\DefaultRole;
use App\Enums\TokenAbility;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function apiGenerationInput(array $overrides = []): array
{
    return ['prompt' => str_repeat('a', 40), 'size' => '1024x1024', 'quality' => 'medium', ...$overrides];
}

describe('generating', function (): void {
    test('holds the maximum, queues the image and answers 202 with where to poll', function (): void {
        fakePixelforgePricing();
        Queue::fake([SubmitGeneration::class]);
        $user = organizationOwner();
        fundOrganization($user->currentOrganization, '5');

        $response = $this->withToken(apiToken($user))->postJson(route('api.generations.store'), apiGenerationInput());

        $generation = Generation::query()->sole();
        $response->assertStatus(202)
            ->assertHeader('Location', route('api.generations.show', $generation))
            ->assertJsonPath('data.type', 'generations')
            ->assertJsonPath('data.attributes.status', 'pending')
            ->assertJsonPath('data.attributes.estimated_charge.max.amount', '0.0863250000')
            ->assertJsonMissingPath('data.attributes.provider_cost')
            ->assertJsonMissingPath('data.attributes.pixelforge_id');
        expect($generation->organization_id)->toBe($user->current_organization_id);
        Queue::assertPushed(SubmitGeneration::class);
    });

    test('follows the same rules as the web app', function (string $case, int $status, string $field): void {
        fakePixelforgePricing();
        Queue::fake([SubmitGeneration::class]);
        $owner = organizationOwner();
        $user = match ($case) {
            'no permission' => tap(organizationOwner(), function (User $user) use ($owner): void {
                addMember($owner->currentOrganization, DefaultRole::Member, $user);
                $owner->currentOrganization->roles()->where('name', 'Member')->sole()->permissions()->detach();
            }),
            default => $owner,
        };
        if ($case === 'unverified') {
            $user->forceFill(['email_verified_at' => null])->save();
        }

        if ($case !== 'no credits') {
            fundOrganization($owner->currentOrganization, '5');
        }

        if ($case === 'over a limit') {
            $owner->currentOrganization->update(['generation_limit_daily' => 1]);
            Generation::factory()->for($owner)->for($owner->currentOrganization)->create();
        }

        $generationsBefore = Generation::query()->count();
        $input = $case === 'invalid input' ? apiGenerationInput(['size' => '640x480', 'prompt' => '']) : apiGenerationInput();

        $response = $this->withToken(apiToken($user, organization: $owner->currentOrganization))->postJson(route('api.generations.store'), $input);

        $response->assertStatus($status);
        if ($field !== '') {
            $response->assertJsonValidationErrors($field);
        }

        expect(Generation::query()->count())->toBe($generationsBefore);
        Queue::assertNothingPushed();
    })->with([
        'no credits, 402 Payment Required' => ['no credits', 402, 'credits'],
        'over a limit' => ['over a limit', 422, 'limit'],
        'invalid input' => ['invalid input', 422, 'size'],
        'unverified' => ['unverified', 403, ''],
        'no permission' => ['no permission', 403, ''],
    ]);

    test('needs the generations:create scope', function (): void {
        $user = organizationOwner();

        $this->withToken(apiToken($user, [TokenAbility::GenerationsRead]))->postJson(route('api.generations.store'), apiGenerationInput())->assertForbidden();
    });
});

describe('reading', function (): void {
    test("lists only the user's generations in the token's organization, newest first", function (): void {
        $user = organizationOwner();
        $older = Generation::factory()->for($user)->for($user->currentOrganization)->create();
        $newer = Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create();
        Generation::factory()->create();

        $this->withToken(apiToken($user, [TokenAbility::GenerationsRead]))->getJson(route('api.generations.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', (string) $newer->id)
            ->assertJsonPath('data.0.attributes.image_url', $newer->image_url)
            ->assertJsonPath('data.1.id', (string) $older->id);
    });

    test("shows one generation, and nobody else's", function (): void {
        $user = organizationOwner();
        $mine = Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create();
        $theirs = Generation::factory()->create();
        $token = apiToken($user, [TokenAbility::GenerationsRead]);

        $this->withToken($token)->getJson(route('api.generations.show', $mine))->assertOk()->assertJsonPath('data.attributes.charge.amount', '0.3818875000');
        $this->withToken($token)->getJson(route('api.generations.show', $theirs))->assertNotFound();
    });

    test('needs the generations:read scope', function (): void {
        $user = organizationOwner();

        $this->withToken(apiToken($user, [TokenAbility::CreditsRead]))->getJson(route('api.generations.index'))->assertForbidden();
    });
});
