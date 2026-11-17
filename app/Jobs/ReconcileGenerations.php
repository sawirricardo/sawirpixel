<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Safety net for generations that stopped moving: a submission whose job was lost, or an accepted
 * generation whose webhooks and polling both missed the result. Runs on a schedule.
 *
 * Re-dispatching is safe: both jobs are unique per generation, submissions reuse the idempotency key,
 * and results are applied through the same forward-only action.
 */
final class ReconcileGenerations implements ShouldQueue
{
    use Queueable;

    /**
     * How long a generation may sit without changing before it is checked again.
     */
    public const int STALE_AFTER_MINUTES = 10;

    public function handle(): void
    {
        $staleBefore = now()->subMinutes(self::STALE_AFTER_MINUTES);

        Generation::query()
            ->where('status', GenerationStatus::Pending)
            ->where('updated_at', '<', $staleBefore)
            ->each(fn (Generation $generation): PendingDispatch => dispatch(new SubmitGeneration($generation)));

        Generation::query()
            ->whereNotNull('pixelforge_id')
            ->whereIn('status', [GenerationStatus::Queued, GenerationStatus::Processing])
            ->where('updated_at', '<', $staleBefore)
            ->each(fn (Generation $generation): PendingDispatch => dispatch(new PollGeneration($generation)));
    }
}
