<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\ApplyPixelforgeGeneration;
use App\Enums\GenerationStatus;
use App\Exceptions\Pixelforge\PixelforgeException;
use App\Models\Generation;
use App\Services\Pixelforge\PixelforgeClient;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Fallback for missed or delayed webhooks: checks the generation until it is final.
 */
#[Backoff([10, 30, 60])]
final class PollGeneration implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int INTERVAL_SECONDS = 10;

    public function __construct(public Generation $generation) {}

    /**
     * Polls share Pixelforge's per-account request limit with submissions.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RateLimited('pixelforge')];
    }

    public function uniqueId(): string
    {
        return (string) $this->generation->id;
    }

    public function retryUntil(): CarbonInterface
    {
        return now()->addMinutes(20);
    }

    public function handle(PixelforgeClient $client, ApplyPixelforgeGeneration $apply): void
    {
        $generation = $this->generation->refresh();

        if ($generation->pixelforge_id === null || $this->isFinal($generation->status)) {
            return;
        }

        try {
            $generation = $apply->handle($generation, $client->getGeneration($generation->pixelforge_id));
        } catch (PixelforgeException $pixelforgeException) {
            if (! $pixelforgeException->isTransient()) {
                // Retrying will not fix a 4xx; stop instead of spending the shared rate limit.
                $this->fail($pixelforgeException);

                return;
            }

            $this->release($pixelforgeException->retryAfter ?? self::INTERVAL_SECONDS);

            return;
        }

        if (! $this->isFinal($generation->status)) {
            $this->release(self::INTERVAL_SECONDS);
        }
    }

    private function isFinal(GenerationStatus $status): bool
    {
        return $status === GenerationStatus::Succeeded || $status === GenerationStatus::Failed;
    }
}
