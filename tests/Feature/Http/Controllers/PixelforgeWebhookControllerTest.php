<?php

declare(strict_types=1);

use App\Enums\CreditTransactionType;
use App\Enums\GenerationStatus;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\Organization;
use App\Models\PixelforgeWebhookEvent;
use App\Services\Billing\CreditLedger;
use Brick\Math\BigDecimal;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Testing\TestResponse;

beforeEach(function (): void {
    config()->set('services.pixelforge.webhook_secret', 'whsec_test');
});

/**
 * @param  array<string, mixed>  $generation
 */
function deliverPixelforgeEvent(string $eventId, string $type, array $generation, string $secret = 'whsec_test'): TestResponse
{
    $body = json_encode([
        'id' => $eventId,
        'object' => 'event',
        'type' => $type,
        'created_at' => '2026-11-17T03:31:00Z',
        'data' => ['object' => $generation],
    ], JSON_THROW_ON_ERROR);
    $timestamp = now()->getTimestamp();

    return test()->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.'.$body, $secret)),
    ], $body);
}

test('records the exact actual cost with the markup when a generation succeeds', function (): void {
    $generation = Generation::factory()->queued()->create(['pixelforge_id' => 'gen_1']);

    $response = deliverPixelforgeEvent('evt_1', 'generation.succeeded', pixelforgeGenerationPayload('succeeded'));

    $response->assertOk()->assertJson(['received' => true, 'duplicate' => false]);
    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Succeeded);
    expect($generation->provider_cost)->toBe('0.305510000');
    expect($generation->charge)->toBe('0.3818875000');
    expect($generation->image_url)->toBe('https://pixelforge.promptedit.dev/images/gen_1.png?signature=abc');
    expect($generation->usage)->toMatchArray(['reasoning_tokens' => 3184, 'total_tokens' => 10411]);
});

test('ignores an event delivered twice', function (): void {
    $generation = Generation::factory()->queued()->create(['pixelforge_id' => 'gen_1']);
    deliverPixelforgeEvent('evt_1', 'generation.succeeded', pixelforgeGenerationPayload('succeeded'));

    $response = deliverPixelforgeEvent('evt_1', 'generation.succeeded', pixelforgeGenerationPayload('succeeded', [
        'usage' => ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 1, 'total_tokens' => 3],
    ]));

    $response->assertOk()->assertJson(['duplicate' => true]);
    expect(PixelforgeWebhookEvent::query()->count())->toBe(1);
    expect($generation->refresh()->charge)->toBe('0.3818875000');
});

test('does not record the cost again when a finished generation is reported by another event', function (): void {
    $generation = Generation::factory()->queued()->create(['pixelforge_id' => 'gen_1']);
    deliverPixelforgeEvent('evt_1', 'generation.succeeded', pixelforgeGenerationPayload('succeeded'));

    deliverPixelforgeEvent('evt_2', 'generation.succeeded', pixelforgeGenerationPayload('succeeded', [
        'usage' => ['input_text_tokens' => 1, 'input_image_tokens' => 0, 'output_tokens' => 1, 'reasoning_tokens' => 1, 'total_tokens' => 3],
    ]));

    expect(PixelforgeWebhookEvent::query()->count())->toBe(2);
    expect($generation->refresh()->charge)->toBe('0.3818875000');
});

test('keeps a finished generation finished when an older event arrives late', function (): void {
    $generation = Generation::factory()->queued()->create(['pixelforge_id' => 'gen_1']);
    deliverPixelforgeEvent('evt_2', 'generation.succeeded', pixelforgeGenerationPayload('succeeded'));

    deliverPixelforgeEvent('evt_1', 'generation.processing', pixelforgeGenerationPayload('processing'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Succeeded);
    expect($generation->charge)->toBe('0.3818875000');
});

test('moves a queued generation forward to processing', function (): void {
    $generation = Generation::factory()->queued()->create(['pixelforge_id' => 'gen_1']);

    deliverPixelforgeEvent('evt_1', 'generation.processing', pixelforgeGenerationPayload('processing'));

    expect($generation->refresh()->status)->toBe(GenerationStatus::Processing);
    expect($generation->charge)->toBeNull();
});

test('charges nothing for a failed generation', function (): void {
    $generation = Generation::factory()->queued()->create(['pixelforge_id' => 'gen_1']);

    deliverPixelforgeEvent('evt_1', 'generation.failed', pixelforgeGenerationPayload('failed'));

    $generation->refresh();
    expect($generation->status)->toBe(GenerationStatus::Failed);
    expect($generation->charge)->toBe('0.0000000000');
    expect($generation->provider_cost)->toBe('0.000000000');
    expect($generation->error_message)->toBe('No image could be produced.');
});

test('matches an event that arrives before the submission stored the pixelforge id', function (): void {
    $generation = Generation::factory()->create();

    deliverPixelforgeEvent('evt_1', 'generation.processing', pixelforgeGenerationPayload('processing', [
        'metadata' => ['generation_id' => (string) $generation->id],
    ]));

    $generation->refresh();
    expect($generation->pixelforge_id)->toBe('gen_1');
    expect($generation->status)->toBe(GenerationStatus::Processing);
});

test('refuses to finalise a success that has no usage, so pixelforge retries the event', function (): void {
    $generation = Generation::factory()->queued()->create(['pixelforge_id' => 'gen_1']);

    $response = deliverPixelforgeEvent('evt_1', 'generation.succeeded', pixelforgeGenerationPayload('succeeded', ['usage' => null]));

    $response->assertServerError();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
    expect(PixelforgeWebhookEvent::query()->count())->toBe(0);
});

test('acknowledges a ping without touching any generation', function (): void {
    $response = deliverPixelforgeEvent('evt_ping', 'ping', []);

    $response->assertOk();

    expect(PixelforgeWebhookEvent::query()->sole()->type)->toBe('ping');
});

test('rejects an event signed with the wrong secret', function (): void {
    $generation = Generation::factory()->queued()->create(['pixelforge_id' => 'gen_1']);

    $response = deliverPixelforgeEvent('evt_1', 'generation.succeeded', pixelforgeGenerationPayload('succeeded'), 'whsec_forged');

    $response->assertBadRequest();

    expect(PixelforgeWebhookEvent::query()->count())->toBe(0);
    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
});

test('rejects a signed body that is not json', function (): void {
    $timestamp = now()->getTimestamp();

    $response = $this->call('POST', route('webhooks.pixelforge'), [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => sprintf('t=%d,v1=%s', $timestamp, hash_hmac('sha256', $timestamp.'.not-json', 'whsec_test')),
    ], 'not-json');

    $response->assertBadRequest();

    expect(PixelforgeWebhookEvent::query()->count())->toBe(0);
});

describe('credits', function (): void {
    /**
     * A generation submitted the normal way: the organization funded, and its maximum held.
     */
    function heldForWebhook(string $funds = '1', string $hold = '0.5'): Generation
    {
        $organization = Organization::factory()->create();
        fundOrganization($organization, $funds);
        $generation = Generation::factory()->queued()->for($organization)->create(['pixelforge_id' => 'gen_1', 'estimated_charge_max' => $hold]);
        $ledger = resolve(CreditLedger::class);
        $ledger->hold($ledger->lockOrganization($organization->id), $generation, BigDecimal::of($hold));

        return $generation;
    }

    test('charges the organization exactly what the generation cost and returns the rest of the hold', function (): void {
        $generation = heldForWebhook();

        deliverPixelforgeEvent('evt_1', 'generation.succeeded', pixelforgeGenerationPayload('succeeded'));

        expect($generation->organization->refresh()->credit_balance)->toBe('0.6181125000');
        expectBalanceMatchesLedger($generation->organization);
    });

    test('charges once however the events arrive', function (): void {
        $generation = heldForWebhook();

        deliverPixelforgeEvent('evt_2', 'generation.succeeded', pixelforgeGenerationPayload('succeeded'));
        deliverPixelforgeEvent('evt_2', 'generation.succeeded', pixelforgeGenerationPayload('succeeded'));
        deliverPixelforgeEvent('evt_1', 'generation.processing', pixelforgeGenerationPayload('processing'));
        deliverPixelforgeEvent('evt_3', 'generation.succeeded', pixelforgeGenerationPayload('succeeded'));

        expect(CreditTransaction::query()->where('generation_id', $generation->id)->where('type', CreditTransactionType::Charge)->count())->toBe(1);
        expect($generation->organization->refresh()->credit_balance)->toBe('0.6181125000');
        expectBalanceMatchesLedger($generation->organization);
    });

    test('returns the whole hold when the generation fails after being accepted', function (): void {
        $generation = heldForWebhook();

        deliverPixelforgeEvent('evt_1', 'generation.failed', pixelforgeGenerationPayload('failed'));

        expect($generation->organization->refresh()->credit_balance)->toBe('1.0000000000');
        expectBalanceMatchesLedger($generation->organization);
    });
});

test('surfaces a database conflict that is not a repeated event instead of acknowledging it', function (): void {
    $generation = Generation::factory()->queued()->create(['pixelforge_id' => 'gen_1']);
    PixelforgeWebhookEvent::creating(fn (): never => throw new UniqueConstraintViolationException('sqlite', 'insert', [], new PDOException('UNIQUE constraint failed')));

    $response = deliverPixelforgeEvent('evt_new', 'generation.succeeded', pixelforgeGenerationPayload('succeeded'));

    $response->assertServerError();

    expect($generation->refresh()->status)->toBe(GenerationStatus::Queued);
    PixelforgeWebhookEvent::flushEventListeners();
});
