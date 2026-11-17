<?php

declare(strict_types=1);

use App\Actions\ApplyPixelforgeGeneration;
use App\Actions\InviteMember;
use App\Enums\DefaultRole;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\OrgInvitation;
use App\Models\User;
use App\Notifications\GenerationFinished;
use App\Notifications\InvitedToOrganization;
use App\Notifications\LowCredits;
use App\Services\Pixelforge\Data\Generation as PixelforgeGeneration;
use App\Services\Pixelforge\PixelforgeClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Apply a Pixelforge result to a queued generation of the owner's organization, as a webhook would.
 */
function finishGeneration(Generation $generation, string $status): void
{
    resolve(ApplyPixelforgeGeneration::class)->handle($generation, PixelforgeGeneration::fromArray(pixelforgeGenerationPayload($status, ['id' => $generation->pixelforge_id])));
}

/**
 * @return list<array<string, mixed>>
 */
function notificationsOf(User $user, string $kind): array
{
    return array_values($user->notifications()->get()->pluck('data')->filter(fn (array $data): bool => $data['kind'] === $kind)->all());
}

describe('finished images', function (): void {
    test('tells the person who generated it, once, when the image is ready', function (): void {
        $user = organizationOwner();
        $generation = Generation::factory()->queued()->for($user)->for($user->currentOrganization)->create(['prompt' => 'A lighthouse at dusk']);

        finishGeneration($generation, 'succeeded');
        finishGeneration($generation, 'succeeded');

        expect(notificationsOf($user, GenerationFinished::KIND))->toBe([[
            'kind' => 'generation_finished',
            'generation_id' => $generation->id,
            'title' => 'Your image is ready',
            'body' => 'A lighthouse at dusk',
            'url' => '/generations/'.$generation->id,
        ]]);
    });

    test('tells them when it failed at the provider or was never accepted', function (string $how): void {
        $user = organizationOwner();
        $generation = Generation::factory()->for($user)->for($user->currentOrganization);

        if ($how === 'provider') {
            finishGeneration($generation->queued()->create(), 'failed');
        } else {
            Http::fake(['pixelforge.promptedit.dev/v1/generations' => Http::response(['error' => ['type' => 'invalid_request_error', 'code' => 'content_policy_violation', 'message' => 'Prompt blocked.']], 400)]);
            (new SubmitGeneration($generation->create()))->handle(resolve(PixelforgeClient::class));
        }

        expect(notificationsOf($user, GenerationFinished::KIND))->toHaveCount(1)
            ->and(notificationsOf($user, GenerationFinished::KIND)[0]['title'])->toBe("Your image couldn't be generated");
    })->with(['provider', 'rejected on submit']);

    test('says nothing while an image is still in progress', function (): void {
        $user = organizationOwner();
        $generation = Generation::factory()->queued()->for($user)->for($user->currentOrganization)->create();

        finishGeneration($generation, 'processing');

        expect($user->notifications()->count())->toBe(0);
    });

    test('counts as read once the image is opened', function (): void {
        $user = organizationOwner();
        $generation = Generation::factory()->queued()->for($user)->for($user->currentOrganization)->create();
        finishGeneration($generation, 'succeeded');

        $this->actingAs($user)->get(route('generations.show', $generation))->assertOk();

        expect($user->unreadNotifications()->count())->toBe(0);
    });
});

describe('low credits', function (): void {
    test('warns the members who can buy credits when a charge takes the balance below the threshold', function (): void {
        $owner = organizationOwner();
        $organization = $owner->currentOrganization;
        $admin = addMember($organization, DefaultRole::Admin);
        $member = addMember($organization, DefaultRole::Member);
        fundOrganization($organization, '1.20');

        // Charges $0.3818875, leaving about $0.82.
        finishGeneration(Generation::factory()->queued()->for($owner)->for($organization)->create(), 'succeeded');

        expect(notificationsOf($owner, LowCredits::KIND))->toBe([[
            'kind' => 'low_credits',
            'organization_id' => $organization->id,
            'title' => 'Acme Studio is running low on credits',
            'body' => '$0.82 left. Buy more to keep generating.',
            'url' => '/credits',
        ]]);
        expect(notificationsOf($admin, LowCredits::KIND))->toHaveCount(1);
        expect(notificationsOf($member, LowCredits::KIND))->toBe([]);
    });

    test('stays quiet above the threshold, and after the first warning until it is read', function (): void {
        $owner = organizationOwner();
        $organization = $owner->currentOrganization;
        fundOrganization($organization, '2.00');
        $finishOne = fn () => finishGeneration(Generation::factory()->queued()->for($owner)->for($organization)->create(), 'succeeded');

        $finishOne();
        $finishOne();
        expect(notificationsOf($owner, LowCredits::KIND))->toBe([]);

        $finishOne();
        $finishOne();
        expect(notificationsOf($owner, LowCredits::KIND))->toHaveCount(1);

        $owner->unreadNotifications()->update(['read_at' => now()]);
        fundOrganization($organization, '0.70'); // About $1.17, so the next image crosses the line again.
        $finishOne();
        expect(notificationsOf($owner, LowCredits::KIND))->toHaveCount(2);
    });

    test('uses the configured threshold', function (): void {
        config()->set('credits.low_balance', '0.10');
        $owner = organizationOwner();
        fundOrganization($owner->currentOrganization, '1.20');

        finishGeneration(Generation::factory()->queued()->for($owner)->for($owner->currentOrganization)->create(), 'succeeded');

        expect(notificationsOf($owner, LowCredits::KIND))->toBe([]);
    });
});

describe('invitations', function (): void {
    test('shows an invitation in the app to someone who already has an account', function (): void {
        Mail::fake();
        $owner = organizationOwner();
        $invitee = User::factory()->create(['email' => 'sam@example.com']);
        $member = $owner->currentOrganization->roles()->where('name', 'Member')->sole();

        $invitation = resolve(InviteMember::class)->handle($owner, $owner->currentOrganization, 'Sam@Example.com', [$member]);
        resolve(InviteMember::class)->resend($invitation);

        expect(notificationsOf($invitee, InvitedToOrganization::KIND))->toBe([[
            'kind' => 'invitation',
            'invitation_id' => $invitation->id,
            'title' => sprintf('%s invited you to Acme Studio', $owner->name),
            'body' => 'Roles: Member',
            'url' => '/invitations/received/'.$invitation->id,
        ]]);
    });

    test('opens and accepts the invitation from the notification, without the emailed link', function (): void {
        Mail::fake();
        $owner = organizationOwner();
        $invitee = User::factory()->create(['email' => 'sam@example.com']);
        $invitation = resolve(InviteMember::class)->handle($owner, $owner->currentOrganization, 'sam@example.com', [$owner->currentOrganization->roles()->where('name', 'Member')->sole()]);

        $this->actingAs($invitee)->get(route('invitations.received', $invitation))->assertInertia(fn (Assert $page): Assert => $page
            ->component('invitations/Show')
            ->where('acceptUrl', '/invitations/received/'.$invitation->id)
            ->where('invitation.organization', 'Acme Studio')
            ->where('emailMatches', true));

        $this->actingAs($invitee)->post(route('invitations.received.accept', $invitation))->assertRedirect(route('home'));

        expect($invitee->refresh()->current_organization_id)->toBe($owner->current_organization_id);
        expect($invitation->refresh()->accepted_at)->not->toBeNull();
        expect($invitee->unreadNotifications()->count())->toBe(0);
    });

    test("can't be opened or accepted by anyone else", function (): void {
        Mail::fake();
        $owner = organizationOwner();
        $invitation = resolve(InviteMember::class)->handle($owner, $owner->currentOrganization, 'sam@example.com', [$owner->currentOrganization->roles()->where('name', 'Member')->sole()]);
        $stranger = User::factory()->create();

        $this->actingAs($stranger)->get(route('invitations.received', $invitation))->assertNotFound();
        $this->actingAs($stranger)->post(route('invitations.received.accept', $invitation))->assertNotFound();
        expect($invitation->refresh()->accepted_at)->toBeNull();
    });

    test('sends nothing in the app to an address without an account', function (): void {
        Mail::fake();
        $owner = organizationOwner();

        resolve(InviteMember::class)->handle($owner, $owner->currentOrganization, 'nobody@example.com', [$owner->currentOrganization->roles()->where('name', 'Member')->sole()]);

        expect(OrgInvitation::query()->count())->toBe(1);
        expect(User::query()->get()->sum(fn (User $user): int => $user->notifications()->count()))->toBe(0);
    });
});

describe('the bell', function (): void {
    test('shares the unread count and the latest notifications, newest first', function (): void {
        $user = organizationOwner();
        $first = Generation::factory()->queued()->for($user)->for($user->currentOrganization)->create(['prompt' => 'First']);
        $second = Generation::factory()->queued()->for($user)->for($user->currentOrganization)->create(['prompt' => 'Second']);
        finishGeneration($first, 'succeeded');
        $this->travel(1)->minute();
        finishGeneration($second, 'failed');

        $this->actingAs($user)->get(route('home'))->assertInertia(fn (Assert $page): Assert => $page
            ->where('notifications.unread', 2)
            ->has('notifications.recent', 2)
            ->where('notifications.recent.0.title', "Your image couldn't be generated")
            ->where('notifications.recent.0.body', 'Second')
            ->where('notifications.recent.0.read', false)
            ->where('notifications.recent.1.body', 'First'));
    });

    test('opening a notification marks it read and goes where it points', function (): void {
        $user = organizationOwner();
        $generation = Generation::factory()->queued()->for($user)->for($user->currentOrganization)->create();
        finishGeneration($generation, 'succeeded');
        $notification = $user->notifications()->sole();

        $this->actingAs($user)->get(route('notifications.show', $notification->id))->assertRedirect('/generations/'.$generation->id);

        expect($notification->refresh()->read_at)->not->toBeNull();
    });

    test("never redirects off the site, and keeps other people's notifications private", function (): void {
        $user = organizationOwner();
        $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['url' => '//evil.example.com']]);
        $notification = $user->notifications()->sole();

        $this->actingAs($user)->get(route('notifications.show', $notification->id))->assertRedirect(route('home'));
        $this->actingAs(User::factory()->create())->get(route('notifications.show', $notification->id))->assertNotFound();
    });

    test('marks everything read', function (): void {
        $user = organizationOwner();
        finishGeneration(Generation::factory()->queued()->for($user)->for($user->currentOrganization)->create(), 'succeeded');
        finishGeneration(Generation::factory()->queued()->for($user)->for($user->currentOrganization)->create(), 'failed');

        $this->actingAs($user)->from(route('home'))->post(route('notifications.read'))->assertRedirect(route('home'));

        expect($user->unreadNotifications()->count())->toBe(0);
    });

    test('is not shared with guests', function (): void {
        $this->get(route('home'))->assertInertia(fn (Assert $page): Assert => $page->where('notifications', null));
    });
});
