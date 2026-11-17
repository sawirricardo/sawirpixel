<?php

declare(strict_types=1);

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\OrgUser;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

describe('store', function (): void {
    test('creates a pending generation with the server-side estimate and queues its submission', function (): void {
        fakePixelforgePricing();
        Queue::fake([SubmitGeneration::class]);
        $user = organizationOwner();

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => str_repeat('a', 40),
            'size' => '1024x1024',
            'quality' => 'medium',
            'reference_images' => ['https://example.com/ref.png'],
            'estimated_charge_min' => '0.00',
            'status' => 'succeeded',
        ]);

        $response->assertRedirect(route('generations.index'));

        $generation = Generation::query()->sole();
        expect($generation->user_id)->toBe($user->id);
        expect($generation->organization_id)->toBe($user->current_organization_id);
        expect($generation->status)->toBe(GenerationStatus::Pending);
        expect($generation->reference_images)->toBe(['https://example.com/ref.png']);
        expect($generation->estimated_charge_min)->toBe('0.0677375000');
        expect($generation->estimated_charge_max)->toBe('0.0958875000');
        Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job): bool => $job->generation->is($generation));
    });

    test('sends a guest to log in', function (): void {
        Queue::fake([SubmitGeneration::class]);

        $response = $this->post(route('generations.store'), ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'medium']);

        $response->assertRedirect(route('login'));
        Queue::assertNothingPushed();
    });

    test('asks a user without an organization to create one first', function (): void {
        Queue::fake([SubmitGeneration::class]);

        $response = $this->actingAs(User::factory()->create())
            ->post(route('generations.store'), ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'medium']);

        $response->assertRedirect(route('organizations.create'));

        expect(Generation::query()->count())->toBe(0);
    });

    test('forbids a member without the generate permission', function (): void {
        Queue::fake([SubmitGeneration::class]);
        $user = User::factory()->create();
        $membership = OrgUser::factory()->for($user)->create();
        $user->currentOrganization()->associate($membership->organization_id)->save();

        $response = $this->actingAs($user)
            ->post(route('generations.store'), ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'medium']);

        $response->assertForbidden();

        expect(Generation::query()->count())->toBe(0);
    });

    test('rejects invalid input', function (array $input, string $field): void {
        Queue::fake([SubmitGeneration::class]);

        $response = $this->actingAs(organizationOwner())->post(route('generations.store'), [
            'prompt' => 'A lighthouse',
            'size' => '1024x1024',
            'quality' => 'medium',
            ...$input,
        ]);

        $response->assertSessionHasErrors($field);
        Queue::assertNothingPushed();
    })->with([
        'missing prompt' => [['prompt' => ''], 'prompt'],
        'unsupported size' => [['size' => '2048x2048'], 'size'],
        'five reference images' => [['reference_images' => array_map(fn (int $i): string => "https://example.com/{$i}.png", range(1, 5))], 'reference_images'],
        'reference image that is not a url' => [['reference_images' => ['not a url']], 'reference_images.0'],
    ]);
});

describe('index', function (): void {
    test("lists only the user's generations in their current organization, newest first", function (): void {
        $user = organizationOwner();
        $older = Generation::factory()->for($user)->for($user->currentOrganization)->create(['prompt' => 'Older']);
        $newer = Generation::factory()->queued()->for($user)->for($user->currentOrganization)->create(['prompt' => 'Newer']);
        Generation::factory()->for($user->currentOrganization)->create(['prompt' => 'Teammate']);
        Generation::factory()->for($user)->create(['prompt' => 'Other organization']);

        $response = $this->actingAs($user)->get(route('generations.index'));

        $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('generations/Index')
            ->has('generations.data', 2)
            ->where('generations.data.0.id', $newer->id)
            ->where('generations.data.0.status', 'queued')
            ->where('generations.data.0.estimated_charge.min', ['amount' => '0.0600000000', 'formatted' => '$0.06'])
            ->where('generations.data.0.estimated_charge.max', ['amount' => '0.0900000000', 'formatted' => '$0.09'])
            ->where('generations.data.0.charge', null)
            ->where('generations.data.1.id', $older->id));
    });

    test('asks a user without an organization to create one first', function (): void {
        $response = $this->actingAs(User::factory()->create())->get(route('generations.index'));

        $response->assertRedirect(route('organizations.create'));
    });
});
