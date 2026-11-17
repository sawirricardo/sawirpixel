<?php

declare(strict_types=1);

use App\Models\Generation;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

test('serves the owner their image from our own address', function (): void {
    Http::fake(['pixelforge.promptedit.dev/images/*' => Http::response('png-bytes')]);
    $user = organizationOwner();
    $generation = Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create(['image_url_expires_at' => now()->addHours(2)]);

    $response = $this->actingAs($user)->get(route('generations.image', $generation));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('Cache-Control', 'max-age=7200, private');
    expect($response->getContent())->toBe('png-bytes');
});

test("never shows the provider's image link or a follow-up's parent link in page data", function (): void {
    $user = organizationOwner();
    $parent = Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create();
    $followUp = Generation::factory()->succeeded()->continuing($parent)->create([
        'reference_images' => [$parent->image_url, 'https://example.com/mine.png'],
    ]);

    $response = $this->actingAs($user)->get(route('generations.show', $followUp));

    $response->assertInertia(fn (Assert $page): Assert => $page
        ->where('generation.image_url', '/generations/'.$followUp->id.'/image')
        ->where('generation.reference_images', ['https://example.com/mine.png']));
    expect($response->getContent())->not->toContain('pixelforge');
});

test('has nothing to serve once the link expired, for someone else, or when the provider fails', function (string $case): void {
    $user = organizationOwner();
    $generation = match ($case) {
        'expired' => Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create(['image_url_expires_at' => now()->subMinute()]),
        'someone else' => Generation::factory()->succeeded()->create(),
        'provider failure' => Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create(),
    };
    Http::fake(['pixelforge.promptedit.dev/images/*' => Http::response('gone', 403)]);

    $this->actingAs($user)->get(route('generations.image', $generation))->assertNotFound();
})->with(['expired', 'someone else', 'provider failure']);
