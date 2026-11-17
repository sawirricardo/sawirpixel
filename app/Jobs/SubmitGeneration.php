<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Models\Generation;
use App\Services\Pixelforge\Data\GenerationRequest;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Throwable;

/**
 * Sends a pending generation to Pixelforge. Every attempt reuses the generation's idempotency key, so a
 * retry after a timeout or crash can never create (or bill) a second generation.
 */
#[Backoff([5, 15, 30])]
final class SubmitGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public Generation $generation) {}

    public function uniqueId(): string
    {
        return (string) $this->generation->id;
    }

    public function retryUntil(): CarbonInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(PixelforgeClient $client): void
    {
        $generation = $this->generation->refresh();

        if ($generation->status !== GenerationStatus::Pending) {
            return;
        }

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
            if ($pixelforgeException->isTransient()) {
                $this->release($pixelforgeException->retryAfter ?? min(60, 5 * $this->attempts()));

                return;
            }

            $this->markFailed($pixelforgeException->errorCode ?? 'submission_rejected', $pixelforgeException->getMessage());

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
     * Give up once retries run out; the generation was never accepted, so it costs nothing.
     */
    public function failed(?Throwable $exception): void
    {
        $this->markFailed('submission_failed', 'We could not reach Pixelforge. You have not been charged.');
    }

    private function markFailed(string $code, string $message): void
    {
        Generation::query()->whereKey($this->generation->id)->where('status', GenerationStatus::Pending)->update([
            'status' => GenerationStatus::Failed,
            'provider_cost' => '0',
            'charge' => '0',
            'error_code' => $code,
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }
}
