<?php

declare(strict_types=1);

use App\Enums\DefaultRole;
use App\Enums\TokenAbility;
use App\Jobs\SubmitGeneration;
use App\Mcp\Servers\SawirpixelServer;
use App\Mcp\Tools\EstimatePriceTool;
use App\Mcp\Tools\GenerateImageTool;
use App\Mcp\Tools\GetCreditBalanceTool;
use App\Mcp\Tools\GetGenerationTool;
use App\Mcp\Tools\ListGenerationsTool;
use App\Models\Generation;
use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Enums\ProtocolVersion;

/**
 * The user acting through a real personal access token, as an MCP client would.
 *
 * @param  list<TokenAbility>  $abilities
 */
function viaToken(User $user, array $abilities = [TokenAbility::GenerationsRead, TokenAbility::GenerationsCreate, TokenAbility::CreditsRead]): User
{
    return $user->withAccessToken(PersonalAccessToken::findToken(apiToken($user, $abilities)));
}

/**
 * @return array<string, mixed>
 */
function mcpGenerationInput(array $overrides = []): array
{
    return ['prompt' => str_repeat('a', 40), 'size' => '1024x1024', 'quality' => 'medium', ...$overrides];
}

describe('tools', function (): void {
    test('estimates a price for the token organization', function (): void {
        fakePixelforgePricing();
        $user = organizationOwner();
        fundOrganization($user->currentOrganization, '0.05');

        SawirpixelServer::actingAs(viaToken($user))->tool(EstimatePriceTool::class, mcpGenerationInput())
            ->assertOk()
            ->assertStructuredContent([
                'charge' => [
                    'min' => ['amount' => '0.0581750000', 'formatted' => '$0.06'],
                    'max' => ['amount' => '0.0863250000', 'formatted' => '$0.09'],
                ],
                'affordable' => false,
                'blocked' => null,
            ]);
    });

    test('generates an image the same way the web app and API do', function (): void {
        fakePixelforgePricing();
        Queue::fake([SubmitGeneration::class]);
        $user = organizationOwner();
        fundOrganization($user->currentOrganization, '5');

        $response = SawirpixelServer::actingAs(viaToken($user))->tool(GenerateImageTool::class, mcpGenerationInput());

        $generation = Generation::query()->sole();
        $response->assertOk()
            ->assertStructuredContentPath('id', $generation->id)
            ->assertStructuredContentPath('status', 'pending')
            ->assertStructuredContentPath('estimated_charge.max.amount', '0.0863250000')
            ->assertStructuredContentMissingPath('provider_cost');
        expect($generation->organization_id)->toBe($user->current_organization_id);
        Queue::assertPushed(SubmitGeneration::class);
    });

    test('explains a refusal instead of generating', function (string $case, string $message): void {
        fakePixelforgePricing();
        Queue::fake([SubmitGeneration::class]);
        $owner = organizationOwner();
        $user = $owner;
        $abilities = [TokenAbility::GenerationsRead, TokenAbility::GenerationsCreate];

        match ($case) {
            'no credits' => null,
            'unverified' => $user->forceFill(['email_verified_at' => null])->save(),
            'missing scope' => $abilities = [TokenAbility::GenerationsRead],
            'no permission' => (function () use ($owner, &$user): void {
                $user = addMember($owner->currentOrganization, DefaultRole::Member);
                $owner->currentOrganization->roles()->where('name', 'Member')->sole()->permissions()->detach();
            })(),
        };

        SawirpixelServer::actingAs(viaToken($user, $abilities))->tool(GenerateImageTool::class, mcpGenerationInput())
            ->assertHasErrors([$message]);
        expect(Generation::query()->count())->toBe(0);
        Queue::assertNothingPushed();
    })->with([
        'no credits' => ['no credits', 'This could cost up to $0.09, but Acme Studio has $0.00 in credits.'],
        'unverified' => ['unverified', 'Verify your email address before generating images.'],
        'missing scope' => ['missing scope', 'This token is missing the generations:create scope.'],
        'no permission' => ['no permission', "Your role in Acme Studio doesn't allow generating images."],
    ]);

    test('shows and lists only the user own generations', function (): void {
        $user = organizationOwner();
        $mine = Generation::factory()->succeeded()->for($user)->for($user->currentOrganization)->create();
        $theirs = Generation::factory()->succeeded()->create();
        $acting = viaToken($user, [TokenAbility::GenerationsRead]);

        SawirpixelServer::actingAs($acting)->tool(GetGenerationTool::class, ['id' => $mine->id])
            ->assertOk()->assertStructuredContentPath('charge.amount', '0.3818875000');
        SawirpixelServer::actingAs($acting)->tool(GetGenerationTool::class, ['id' => $theirs->id])
            ->assertHasErrors(['No generation with that id in this organization.']);
        SawirpixelServer::actingAs($acting)->tool(ListGenerationsTool::class)
            ->assertOk()
            ->assertStructuredContentCount(1, 'generations')
            ->assertStructuredContentPath('generations.0.id', $mine->id)
            ->assertStructuredContentPath('has_more', false);
    });

    test('shows the credit balance', function (): void {
        $user = organizationOwner();
        fundOrganization($user->currentOrganization, '10');

        SawirpixelServer::actingAs(viaToken($user, [TokenAbility::CreditsRead]))->tool(GetCreditBalanceTool::class)
            ->assertOk()
            ->assertStructuredContent([
                'organization' => 'Acme Studio',
                'balance' => ['amount' => '10.0000000000', 'formatted' => '$10.00'],
                'reserved' => ['amount' => '0', 'formatted' => '$0.00'],
            ]);
    });

    test('refuses a token whose user left its organization', function (): void {
        $owner = organizationOwner();
        $member = addMember($owner->currentOrganization, DefaultRole::Member);
        $acting = viaToken($member, [TokenAbility::CreditsRead]);
        $member->membershipIn($owner->currentOrganization)?->delete();

        SawirpixelServer::actingAs($acting)->tool(GetCreditBalanceTool::class)
            ->assertHasErrors(["This token's organization no longer exists, or you're no longer a member of it."]);
    });

    test('refuses every tool to a token without its scope', function (string $tool, array $arguments, string $scope): void {
        $user = organizationOwner();
        $otherScopes = array_values(array_filter(TokenAbility::cases(), fn (TokenAbility $ability): bool => $ability->value !== $scope));

        SawirpixelServer::actingAs(viaToken($user, $otherScopes))->tool($tool, $arguments)
            ->assertHasErrors([sprintf('This token is missing the %s scope. Add it to the token in your profile.', $scope)]);
    })->with([
        'estimate_price' => [EstimatePriceTool::class, ['size' => '1024x1024', 'quality' => 'low'], 'generations:read'],
        'generate_image' => [GenerateImageTool::class, ['prompt' => 'x', 'size' => '1024x1024', 'quality' => 'low'], 'generations:create'],
        'get_generation' => [GetGenerationTool::class, ['id' => 1], 'generations:read'],
        'list_generations' => [ListGenerationsTool::class, [], 'generations:read'],
        'get_credit_balance' => [GetCreditBalanceTool::class, [], 'credits:read'],
    ]);

    test('refuses a call made without a token', function (): void {
        SawirpixelServer::tool(GetCreditBalanceTool::class)
            ->assertHasErrors(['Authenticate with a personal access token: Authorization: Bearer <token>.']);
    });
});

describe('over HTTP', function (): void {
    test('needs a token', function (): void {
        $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])->assertUnauthorized();
        // Even for a client that doesn't ask for JSON: an API endpoint never redirects to a login page.
        $this->call('POST', '/mcp', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertUnauthorized();
    });

    test('lists the tools to a client with a token', function (): void {
        $token = apiToken(organizationOwner());

        $version = ProtocolVersion::LATEST->value;

        $response = $this->withToken($token)->postJson(
            '/mcp',
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => ['_meta' => ['io.modelcontextprotocol/protocolVersion' => $version, 'io.modelcontextprotocol/clientCapabilities' => (object) [], 'io.modelcontextprotocol/clientInfo' => ['name' => 'test', 'version' => '1.0']]]],
            ['MCP-Protocol-Version' => $version, 'Mcp-Method' => 'tools/list', 'Accept' => 'application/json, text/event-stream'],
        );

        $response->assertOk();

        expect(collect($response->json('result.tools'))->pluck('name')->all())
            ->toBe(['estimate_price', 'generate_image', 'get_generation', 'list_generations', 'get_credit_balance']);
    });
});

test('publishes a server card that matches the server', function (): void {
    $card = $this->getJson('/.well-known/mcp/server-card.json')->assertOk();

    $card->assertJsonPath('serverInfo.name', 'Sawirpixel')
        ->assertJsonPath('transport', ['type' => 'streamable-http', 'endpoint' => url('/mcp')])
        ->assertJsonPath('authentication.required', true)
        ->assertJsonPath('authentication.schemes', ['bearer'])
        ->assertJsonPath('tools.1.name', 'generate_image')
        ->assertJsonPath('tools.1.inputSchema.required', ['prompt', 'size', 'quality']);
    expect(collect($card->json('tools'))->pluck('name')->all())
        ->toBe(['estimate_price', 'generate_image', 'get_generation', 'list_generations', 'get_credit_balance']);
    expect((string) $this->get(route('home'))->headers->get('Link'))->toContain('</.well-known/mcp/server-card.json>; rel="mcp-server-card"');
});
