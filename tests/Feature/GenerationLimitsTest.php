<?php

declare(strict_types=1);

use App\Enums\DefaultRole;
use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function (): void {
    $this->travelTo('2026-11-17 12:00:00');
    fakePixelforgePricing();
    Queue::fake([SubmitGeneration::class]);
});

/**
 * A 40-character prompt at 1024x1024: its estimate's maximum is $0.0863250000 at medium quality.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function limitedRequest(array $overrides = []): array
{
    return ['prompt' => str_repeat('a', 40), 'size' => '1024x1024', 'quality' => 'medium', ...$overrides];
}

/**
 * @return array{owner: User, member: User, organization: Organization}
 */
function limitedOrganization(): array
{
    $owner = organizationOwner();
    $organization = $owner->currentOrganization;
    fundOrganization($organization, '10');
    $member = addMember($organization, DefaultRole::Member);

    return ['owner' => $owner, 'member' => $member, 'organization' => $organization->refresh()];
}

describe('spending limits', function (): void {
    test('allow a generation that reaches the limit exactly, and refuse one that would pass it', function (string $limit, bool $allowed): void {
        ['owner' => $owner, 'organization' => $organization] = limitedOrganization();
        Generation::factory()->succeeded()->for($owner)->for($organization)->create(); // Charged $0.3818875000.
        $organization->update(['spend_limit_monthly' => $limit]);

        $response = $this->actingAs($owner)->post(route('generations.store'), limitedRequest());

        if ($allowed) {
            $response->assertSessionHasNoErrors();
            expect(Generation::query()->count())->toBe(2);
        } else {
            $response->assertSessionHasErrors(['limit' => 'Acme Studio can spend up to $0.46 a month on images. $0.39 is used, and this image could cost up to $0.09. The limit resets on 1 December. Change it in organization settings.']);
            expect(Generation::query()->count())->toBe(1);
        }
    })->with([
        'exactly at the limit' => ['0.4682125000', true],
        'a fraction over' => ['0.4682124999', false],
    ]);

    test('count an image in progress at its maximum and a failed one as nothing', function (): void {
        ['owner' => $owner, 'organization' => $organization] = limitedOrganization();
        Generation::factory()->queued()->for($owner)->for($organization)->create(['estimated_charge_max' => '0.40']);
        Generation::factory()->for($owner)->for($organization)->create(['status' => GenerationStatus::Failed, 'estimated_charge_max' => '5', 'charge' => '0']);

        $organization->update(['spend_limit_daily' => '0.49']);
        $this->actingAs($owner)->postJson(route('generations.estimate'), limitedRequest())->assertJsonPath('blocked', null);

        $organization->update(['spend_limit_daily' => '0.48']);
        $this->actingAs($owner)->postJson(route('generations.estimate'), limitedRequest())
            ->assertJsonPath('blocked', 'Acme Studio can spend up to $0.48 a day on images. $0.40 is used, and this image could cost up to $0.09. The limit resets at midnight UTC. Change it in organization settings.');
    });

    test("only count this period's generations", function (): void {
        ['owner' => $owner, 'organization' => $organization] = limitedOrganization();
        $this->travelTo('2026-10-31 23:59:00');
        Generation::factory()->succeeded()->for($owner)->for($organization)->create(['charge' => '5']);
        $this->travelTo('2026-11-01 00:00:00');
        $organization->update(['spend_limit_monthly' => '1', 'spend_limit_daily' => '1']);

        $this->actingAs($owner)->postJson(route('generations.estimate'), limitedRequest())->assertJsonPath('blocked', null);
    });

    test('limit each member separately, without limiting owners and admins', function (): void {
        ['owner' => $owner, 'member' => $member, 'organization' => $organization] = limitedOrganization();
        $colleague = addMember($organization, DefaultRole::Member);
        Generation::factory()->succeeded()->for($member)->for($organization)->create();
        Generation::factory()->succeeded()->for($owner)->for($organization)->create(['charge' => '3']);
        $organization->update(['member_spend_limit_monthly' => '0.40']);

        $this->actingAs($member)->postJson(route('generations.estimate'), limitedRequest())
            ->assertJsonPath('blocked', 'You can spend up to $0.40 a month on images in Acme Studio. $0.39 is used, and this image could cost up to $0.09. The limit resets on 1 December. Ask an owner or admin to raise it.');
        $this->actingAs($colleague)->postJson(route('generations.estimate'), limitedRequest())->assertJsonPath('blocked', null);
        $this->actingAs($owner)->postJson(route('generations.estimate'), limitedRequest())->assertJsonPath('blocked', null);
    });
});

describe('image limits', function (): void {
    test("limit each member's images per day", function (): void {
        ['owner' => $owner, 'member' => $member, 'organization' => $organization] = limitedOrganization();
        $organization->update(['member_generation_limit_daily' => 1]);
        $this->travelTo('2026-11-16 23:00:00');
        Generation::factory()->for($member)->for($organization)->create();
        $this->travelTo('2026-11-17 12:00:00');

        $this->actingAs($member)->post(route('generations.store'), limitedRequest())->assertSessionHasNoErrors();
        $this->actingAs($member)->post(route('generations.store'), limitedRequest())
            ->assertSessionHasErrors(['limit' => "You can generate up to 1 image a day in Acme Studio, and you've used them all. The limit resets at midnight UTC. Ask an owner or admin to raise it."]);
        $this->actingAs($owner)->post(route('generations.store'), limitedRequest())->assertSessionHasNoErrors();
    });

    test("limit the whole organization's images, owners included", function (): void {
        ['owner' => $owner, 'member' => $member, 'organization' => $organization] = limitedOrganization();
        Generation::factory()->count(2)->for($member)->for($organization)->create();
        $organization->update(['generation_limit_monthly' => 2]);

        $this->actingAs($owner)->post(route('generations.store'), limitedRequest())
            ->assertSessionHasErrors(['limit' => 'Acme Studio can generate up to 2 images a month, and all of them have been used. The limit resets on 1 December. Change it in organization settings.']);
    });

    test('apply to follow-ups too, and refuse without creating, holding or queueing anything', function (): void {
        ['owner' => $owner, 'organization' => $organization] = limitedOrganization();
        $parent = Generation::factory()->succeeded()->for($owner)->for($organization)->create();
        $organization->update(['generation_limit_daily' => 1]);
        $holdsBefore = CreditTransaction::query()->count();

        $this->actingAs($owner)->post(route('generations.store'), limitedRequest(['parent_id' => $parent->id]))->assertSessionHasErrors('limit');

        expect(Generation::query()->count())->toBe(1);
        expect(CreditTransaction::query()->count())->toBe($holdsBefore);
        Queue::assertNothingPushed();
    });
});

describe('quality and size', function (): void {
    test('restrict what members can pick, but not owners', function (): void {
        ['owner' => $owner, 'member' => $member, 'organization' => $organization] = limitedOrganization();
        $organization->update(['member_qualities' => ['low', 'medium'], 'member_sizes' => ['1024x1024']]);

        $this->actingAs($member)->post(route('generations.store'), limitedRequest(['quality' => 'high']))
            ->assertSessionHasErrors(['limit' => 'High quality is turned off for members of Acme Studio. Ask an owner or admin if you need it.']);
        $this->actingAs($member)->postJson(route('generations.estimate'), limitedRequest(['size' => '1536x1024']))
            ->assertJsonPath('blocked', 'The landscape size is turned off for members of Acme Studio. Ask an owner or admin if you need it.');
        $this->actingAs($owner)->post(route('generations.store'), limitedRequest(['quality' => 'high']))->assertSessionHasNoErrors();

        $this->actingAs($member)->get(route('home'))->assertInertia(fn (Assert $page): Assert => $page
            ->where('auth.generationOptions', ['qualities' => ['low', 'medium'], 'sizes' => ['1024x1024']]));
        $this->actingAs($owner)->get(route('home'))->assertInertia(fn (Assert $page): Assert => $page
            ->where('auth.generationOptions.qualities', ['low', 'medium', 'high']));
    });
});

describe('settings', function (): void {
    test('owners set the limits; allowing every option is stored as no restriction', function (): void {
        ['owner' => $owner, 'organization' => $organization] = limitedOrganization();

        $this->actingAs($owner)->patch(route('organization.limits'), [
            'spend_limit_monthly' => '25.50',
            'generation_limit_daily' => '40',
            'member_spend_limit_daily' => '',
            'member_qualities' => ['low', 'medium'],
            'member_sizes' => ['1024x1024', '1536x1024', '1024x1536'],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $organization->refresh();
        expect($organization->spend_limit_monthly)->toBe('25.5000000000');
        expect($organization->generation_limit_daily)->toBe(40);
        expect($organization->member_spend_limit_daily)->toBeNull();
        expect($organization->member_qualities)->toBe(['low', 'medium']);
        expect($organization->member_sizes)->toBeNull();
    });

    test('members cannot change them', function (): void {
        ['member' => $member, 'organization' => $organization] = limitedOrganization();

        $this->actingAs($member)->patch(route('organization.limits'), ['spend_limit_monthly' => '1', 'member_qualities' => ['low'], 'member_sizes' => ['1024x1024']])->assertForbidden();

        expect($organization->refresh()->spend_limit_monthly)->toBeNull();
    });

    test('refuse limits that make no sense', function (array $input, string $field): void {
        ['owner' => $owner] = limitedOrganization();

        $this->actingAs($owner)->patch(route('organization.limits'), [
            'member_qualities' => ['low'],
            'member_sizes' => ['1024x1024'],
            ...$input,
        ])->assertSessionHasErrors($field);
    })->with([
        'no qualities left' => [['member_qualities' => []], 'member_qualities'],
        'no sizes left' => [['member_sizes' => []], 'member_sizes'],
        'a zero spend' => [['spend_limit_daily' => '0'], 'spend_limit_daily'],
        'fractions of a cent' => [['spend_limit_daily' => '1.005'], 'spend_limit_daily'],
        'zero images' => [['generation_limit_daily' => '0'], 'generation_limit_daily'],
    ]);

    test('show the limits and what has been used against them', function (): void {
        ['owner' => $owner, 'organization' => $organization] = limitedOrganization();
        Generation::factory()->succeeded()->for($owner)->for($organization)->create();
        $organization->update(['spend_limit_monthly' => '20']);

        $this->actingAs($owner)->get(route('organization.settings'))->assertInertia(fn (Assert $page): Assert => $page
            ->where('limits.values.spend_limit_monthly', '20.00')
            ->where('limits.values.generation_limit_daily', null)
            ->where('limits.values.member_qualities', ['low', 'medium', 'high'])
            ->where('limits.usage.month.spent.formatted', '$0.38')
            ->where('limits.usage.day.generations', 1)
            ->where('limits.resets.month', '2026-12-01T00:00:00+00:00'));
    });
});
