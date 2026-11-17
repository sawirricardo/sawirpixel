<?php

declare(strict_types=1);

use App\Enums\TokenAbility;

test('prices a generation for the token organization, with whether it is affordable or blocked', function (): void {
    fakePixelforgePricing();
    $user = organizationOwner();
    fundOrganization($user->currentOrganization, '0.05');

    $this->withToken(apiToken($user, [TokenAbility::GenerationsRead]))
        ->postJson(route('api.estimates'), ['prompt' => str_repeat('a', 40), 'size' => '1024x1024', 'quality' => 'medium'])
        ->assertOk()
        ->assertExactJson(['data' => [
            'charge' => [
                'min' => ['amount' => '0.0581750000', 'formatted' => '$0.06'],
                'max' => ['amount' => '0.0863250000', 'formatted' => '$0.09'],
            ],
            'affordable' => false,
            'blocked' => null,
        ]]);
});

test('needs the generations:read scope', function (): void {
    $this->withToken(apiToken(organizationOwner(), [TokenAbility::CreditsRead]))
        ->postJson(route('api.estimates'), ['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertForbidden();
});
