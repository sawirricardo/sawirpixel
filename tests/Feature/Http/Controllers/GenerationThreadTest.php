<?php

declare(strict_types=1);

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * A thread: v1, continued by v2, which was then branched into v3a and v3b.
 *
 * @return array{user: User, v1: Generation, v2: Generation, v3a: Generation, v3b: Generation}
 */
function branchedThread(): array
{
    $user = organizationOwner();
    $v1 = Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create(['prompt' => 'A lighthouse']);
    $v2 = Generation::factory()->succeeded()->continuing($v1)->create(['prompt' => 'Stormier sky']);
    $v3a = Generation::factory()->succeeded()->continuing($v2)->create(['prompt' => 'Add a ship']);
    $v3b = Generation::factory()->succeeded()->continuing($v2)->create(['prompt' => 'Make it night']);

    return ['user' => $user, 'v1' => $v1, 'v2' => $v2, 'v3a' => $v3a, 'v3b' => $v3b];
}

describe('continuing', function (): void {
    test('creates a follow-up that sends the parent image as a reference and joins its thread', function (): void {
        fakePixelforgePricing();
        Queue::fake([SubmitGeneration::class]);
        $user = organizationOwner();
        fundOrganization($user->currentOrganization, '5');
        $parent = Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create();

        $response = $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'Make the sky stormier',
            'size' => '1024x1024',
            'quality' => 'medium',
            'parent_id' => $parent->id,
            'reference_images' => ['https://example.com/extra.png'],
        ]);

        $followUp = Generation::query()->latest('id')->firstOrFail();
        $response->assertRedirect(route('generations.show', $followUp));
        expect($followUp->parent_id)->toBe($parent->id);
        expect($followUp->thread_id)->toBe($parent->thread_id);
        expect($followUp->reference_images)->toBe([$parent->image_url, 'https://example.com/extra.png']);
    });

    test('starts a new thread for a fresh prompt', function (): void {
        fakePixelforgePricing();
        Queue::fake([SubmitGeneration::class]);
        $user = organizationOwner();
        fundOrganization($user->currentOrganization, '5');

        $this->actingAs($user)->post(route('generations.store'), ['prompt' => 'A lighthouse', 'size' => '1024x1024', 'quality' => 'low']);

        $generation = Generation::query()->sole();
        expect($generation->parent_id)->toBeNull();
        expect($generation->thread_id)->toBe($generation->id);
    });

    test('refuses to continue from a version that cannot be continued', function (string $case, string $message): void {
        fakePixelforgePricing();
        Queue::fake([SubmitGeneration::class]);
        $user = organizationOwner();
        fundOrganization($user->currentOrganization, '5');
        $parent = match ($case) {
            'expired' => Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create(['image_url_expires_at' => now()->subMinute()]),
            'unfinished' => Generation::factory()->queued()->for($user)->for($user->currentOrganization)->create(),
            "someone else's" => Generation::factory()->succeeded()->create(),
        };

        $this->actingAs($user)->post(route('generations.store'), ['prompt' => 'Stormier', 'size' => '1024x1024', 'quality' => 'low', 'parent_id' => $parent->id])
            ->assertSessionHasErrors(['parent_id' => $message]);

        expect(Generation::query()->where('parent_id', $parent->id)->count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'expired' => ['expired', "That version's image link has expired, so it can't be continued. Start a new image instead."],
        'unfinished' => ['unfinished', 'You can only continue from a finished image.'],
        "someone else's" => ["someone else's", 'That version could not be found.'],
    ]);

    test('leaves room for the parent image among the reference images', function (): void {
        Queue::fake([SubmitGeneration::class]);
        $user = organizationOwner();
        $parent = Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create();

        $this->actingAs($user)->post(route('generations.store'), [
            'prompt' => 'Stormier',
            'size' => '1024x1024',
            'quality' => 'low',
            'parent_id' => $parent->id,
            'reference_images' => array_map(fn (int $i): string => "https://example.com/{$i}.png", range(1, 4)),
        ])->assertSessionHasErrors('reference_images');
    });

    test('counts the parent image in the estimate', function (): void {
        fakePixelforgePricing();
        $user = organizationOwner();
        $parent = Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create();
        $input = ['prompt' => str_repeat('a', 40), 'size' => '1024x1024', 'quality' => 'medium'];

        $plain = $this->actingAs($user)->postJson(route('generations.estimate'), $input)->json('charge.max.amount');
        $followUp = $this->actingAs($user)->postJson(route('generations.estimate'), [...$input, 'parent_id' => $parent->id])->json('charge.max.amount');

        expect($plain)->toBe('0.0863250000');
        expect($followUp)->toBe('0.0958875000');
    });
});

describe('thread page', function (): void {
    test('shows the path from the first prompt to this version with the branches at each step', function (): void {
        ['user' => $user, 'v1' => $v1, 'v2' => $v2, 'v3a' => $v3a, 'v3b' => $v3b] = branchedThread();

        $response = $this->actingAs($user)->get(route('generations.show', $v3a));

        $response->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('generations/Show')
            ->has('thread', 3)
            ->where('thread.0.id', $v1->id)
            ->where('thread.1.id', $v2->id)
            ->where('thread.2.id', $v3a->id)
            ->where('thread.2.branches', ['position' => 1, 'total' => 2, 'tips' => [$v3a->id, $v3b->id]])
            ->where('thread.0.branches', ['position' => 1, 'total' => 1, 'tips' => [$v3b->id]])
            ->where('thread.2.can_continue', true)
            ->where('latestFollowUpId', null));
    });

    test('maps every version by branch, marking the path to the one being viewed', function (): void {
        ['user' => $user, 'v1' => $v1, 'v2' => $v2, 'v3a' => $v3a, 'v3b' => $v3b] = branchedThread();

        $response = $this->actingAs($user)->get(route('generations.show', $v3b));

        $response->assertInertia(fn (Assert $page): Assert => $page
            ->where('branchCount', 2)
            ->where('versions', [
                ['id' => $v1->id, 'number' => 1, 'depth' => 0, 'prompt' => 'A lighthouse', 'status' => 'succeeded', 'image_url' => $v1->image_url, 'on_path' => true, 'is_current' => false],
                ['id' => $v2->id, 'number' => 2, 'depth' => 1, 'prompt' => 'Stormier sky', 'status' => 'succeeded', 'image_url' => $v2->image_url, 'on_path' => true, 'is_current' => false],
                ['id' => $v3a->id, 'number' => 3, 'depth' => 2, 'prompt' => 'Add a ship', 'status' => 'succeeded', 'image_url' => $v3a->image_url, 'on_path' => false, 'is_current' => false],
                ['id' => $v3b->id, 'number' => 4, 'depth' => 2, 'prompt' => 'Make it night', 'status' => 'succeeded', 'image_url' => $v3b->image_url, 'on_path' => true, 'is_current' => true],
            ]));
    });

    test('leaves out thumbnails whose image link has expired', function (): void {
        $user = organizationOwner();
        $v1 = Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create(['image_url_expires_at' => now()->subMinute()]);
        $v2 = Generation::factory()->succeeded()->continuing($v1)->create();

        $this->actingAs($user)->get(route('generations.show', $v2))->assertInertia(fn (Assert $page): Assert => $page
            ->where('versions.0.image_url', null)
            ->where('versions.1.image_url', $v2->image_url)
            ->where('branchCount', 1));
    });

    test("lists only the reference images a follow-up's author added", function (): void {
        $user = organizationOwner();
        $parent = Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create();
        $followUp = Generation::factory()->succeeded()->continuing($parent)->create([
            'reference_images' => [$parent->image_url, 'https://example.com/mine.png'],
        ]);

        $this->actingAs($user)->get(route('generations.show', $followUp))->assertInertia(fn (Assert $page): Assert => $page
            ->where('generation.image_url', $followUp->image_url)
            ->where('generation.reference_images', ['https://example.com/mine.png']));
    });

    test('points an earlier version to where its branch continues', function (): void {
        ['user' => $user, 'v2' => $v2, 'v3b' => $v3b] = branchedThread();

        $response = $this->actingAs($user)->get(route('generations.show', $v2));

        $response->assertInertia(fn (Assert $page): Assert => $page
            ->has('thread', 2)
            ->where('latestFollowUpId', $v3b->id));
    });

    test('lists one sidebar entry per thread, titled by its first prompt, linking to its newest version', function (): void {
        ['user' => $user, 'v3b' => $v3b] = branchedThread();
        $other = Generation::factory()->for($user)->for($user->currentOrganization)->create(['prompt' => 'A separate idea']);

        $this->actingAs($user)->get(route('home'))->assertInertia(fn (Assert $page): Assert => $page
            ->has('recentGenerations', 2)
            ->where('recentGenerations.0.id', $other->id)
            ->where('recentGenerations.0.versions', 1)
            ->where('recentGenerations.1.id', $v3b->id)
            ->where('recentGenerations.1.prompt', 'A lighthouse')
            ->where('recentGenerations.1.versions', 4)
            ->where('recentGenerations.1.branches', 2)
            ->where('recentGenerations.0.branches', 1));
    });
});
