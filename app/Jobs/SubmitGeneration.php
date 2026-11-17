<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Models\Generation;
use App\Notifications\GenerationFinished;
use App\Services\Billing\CreditLedger;
use App\Services\Generations\FailureMessage;
use App\Services\Pixelforge\Data\GenerationRequest;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends a pending generation to Pixelforge. Every attempt reuses the generation's idempotency key, so a
 * retry after a timeout or crash can never create (or bill) a second generation.
 *
 * Pixelforge allows only a few generations in flight per account, so submissions wait their turn here
 * instead of bouncing off its concurrency limit.
 */
#[Backoff([5, 15, 30])]
final class SubmitGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * How often to check for a free slot while Pixelforge is at its concurrency limit.
     */
    public const int WAIT_FOR_SLOT_SECONDS = 5;

    /**
     * Generations submitted longer ago than this no longer count as in flight. Pixelforge finishes within
     * minutes, so anything older is a result we missed, which ReconcileGenerations will pick up.
     */
    public const int IN_FLIGHT_WINDOW_MINUTES = 30;

    public function __construct(public Generation $generation) {}

    public function uniqueId(): string
    {
        return (string) $this->generation->id;
    }

    public function retryUntil(): CarbonInterface
    {
        return now()->addHour();
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('pixelforge')];
    }

    public function handle(PixelforgeClient $client): void
    {
        $generation = $this->generation->refresh();

        if ($generation->status !== GenerationStatus::Pending) {
            return;
        }

        // One submission at a time decides whether a slot is free, so two workers can't both take the last one.
        $lock = Cache::lock('pixelforge:submit', 30);

        if (! $lock->get()) {
            $this->release(1);

            return;
        }

        try {
            if ($this->inFlightCount() >= config()->integer('services.pixelforge.max_concurrent_generations')) {
                $this->release(self::WAIT_FOR_SLOT_SECONDS);

                return;
            }

            $this->submit($client, $generation);
        } finally {
            $lock->release();
        }
    }

    /**
     * Give up once retries run out. As far as we know it was never accepted, so it costs nothing; if
     * Pixelforge did accept it and reports later, that report wins (an unconfirmed failure).
     */
    public function failed(?Throwable $exception): void
    {
        $this->markFailed(Generation::UNCONFIRMED_FAILURE);
    }

    private function submit(PixelforgeClient $client, Generation $generation): void
    {
        try {
            $accepted = $client->createGeneration(
                new GenerationRequest(
                    prompt: $generation->prompt,
                    size: $generation->size,
                    quality: $generation->quality,
                    referenceImages: $generation->reference_images,
                    metadata: ['generation_id' => (string) $generation->id],
                ),
                $generation->idempotency_key,
            );
        } catch (PixelforgeException $pixelforgeException) {
            if ($pixelforgeException->isDailyLimit()) {
                $this->markFailed('daily_limit_exceeded');

                return;
            }

            if ($pixelforgeException->isTransient()) {
                $this->release($pixelforgeException->retryAfter ?? min(60, 5 * $this->attempts()));

                return;
            }

            $this->markFailed($pixelforgeException->errorCode ?? 'submission_rejected');

            return;
        }

        Generation::query()->whereKey($generation->id)->whereNull('pixelforge_id')->update(['pixelforge_id' => $accepted->id]);
        Generation::query()->whereKey($generation->id)->where('status', GenerationStatus::Pending)->update([
            'status' => GenerationStatus::Queued,
            'submitted_at' => now(),
        ]);

        dispatch(new PollGeneration($generation))->delay(now()->addSeconds(5));
    }

    /**
     * Generations Pixelforge has accepted that haven't finished yet. The limit is per Pixelforge account,
     * so this counts across every organization.
     */
    private function inFlightCount(): int
    {
        return Generation::query()
            ->whereNotNull('pixelforge_id')
            ->whereIn('status', GenerationStatus::atProvider())
            ->where('submitted_at', '>=', now()->subMinutes(self::IN_FLIGHT_WINDOW_MINUTES))
            ->count();
    }

    /**
     * The generation was never accepted, so it costs nothing: mark it failed and return its hold. Only
     * the call that actually moves it out of pending releases anything.
     */
    private function markFailed(string $code): void
    {
        DB::transaction(function () use ($code): void {
            $updated = Generation::query()->whereKey($this->generation->id)->where('status', GenerationStatus::Pending)->update([
                'status' => GenerationStatus::Failed,
                'provider_cost' => '0',
                'charge' => '0',
                'error_code' => $code,
                'error_message' => FailureMessage::for($code),
                'completed_at' => now(),
            ]);

            if ($updated === 1) {
                $generation = Generation::query()->findOrFail($this->generation->id);
                resolve(CreditLedger::class)->settle($generation, null);
                DB::afterCommit(fn () => $generation->user?->notify(new GenerationFinished($generation)));
            }
        });
    }
}
