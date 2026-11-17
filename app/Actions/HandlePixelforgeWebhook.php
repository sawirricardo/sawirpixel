<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Generation;
use App\Models\PixelforgeWebhookEvent;
use App\Services\Pixelforge\Data\Generation as PixelforgeGeneration;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Fluent;

/**
 * Records a verified webhook event once and applies its generation snapshot.
 */
final readonly class HandlePixelforgeWebhook
{
    public function __construct(private ApplyPixelforgeGeneration $apply) {}

    /**
     * @param  array<array-key, mixed>  $payload
     * @return bool false when the event was already received and has been ignored
     */
    public function handle(array $payload): bool
    {
        $event = new Fluent($payload);
        $object = $event->array('data.object');
        $snapshot = str_starts_with($event->string('type')->value(), 'generation.') && $object !== []
            ? PixelforgeGeneration::fromArray($object)
            : null;

        $generation = $snapshot instanceof PixelforgeGeneration ? $this->findGeneration($snapshot) : null;

        try {
            DB::transaction(function () use ($event, $payload, $snapshot, $generation): void {
                PixelforgeWebhookEvent::query()->create([
                    'event_id' => $event->string('id')->value(),
                    'type' => $event->string('type')->value(),
                    'pixelforge_generation_id' => $snapshot?->id,
                    'generation_id' => $generation?->id,
                    'payload' => $payload,
                ]);

                if ($snapshot instanceof PixelforgeGeneration && $generation instanceof Generation) {
                    $this->apply->handle($generation, $snapshot);
                }
            });
        } catch (UniqueConstraintViolationException $uniqueConstraintViolationException) {
            // Only a repeat of an event we've already recorded is a harmless duplicate; any other
            // collision is a real error and must surface so Pixelforge retries.
            throw_unless(PixelforgeWebhookEvent::query()->where('event_id', $event->string('id')->value())->exists(), $uniqueConstraintViolationException);

            return false;
        }

        return true;
    }

    /**
     * Events can arrive before the submit job has stored the Pixelforge id, so fall back to the
     * generation id that was sent as metadata.
     */
    private function findGeneration(PixelforgeGeneration $snapshot): ?Generation
    {
        $metadataId = (new Fluent($snapshot->attributes))->string('metadata.generation_id')->value();

        return Generation::query()->where('pixelforge_id', $snapshot->id)->first()
            ?? ($metadataId !== '' ? Generation::query()->whereKey($metadataId)->whereNull('pixelforge_id')->first() : null);
    }
}
