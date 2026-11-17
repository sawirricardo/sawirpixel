<?php

declare(strict_types=1);

namespace App\Enums;

use App\Enums\Pixelforge\GenerationStatus as PixelforgeStatus;

/**
 * The lifecycle of a generation, as an explicit state machine.
 *
 *     Pending ──▶ Queued ──▶ Processing ──▶ Succeeded
 *        │          │            │
 *        └──────────┴────────────┴───────▶ Failed
 *
 * Pending: created and its credits held, but not accepted by Pixelforge yet. Queued and Processing: at
 * Pixelforge. Succeeded and Failed are final: a generation never leaves them, and its credits are settled
 * exactly once on the way in.
 *
 * Status only moves forward, and may skip steps: a webhook or poll can report a finished generation we
 * last saw as Pending or Queued. An older or repeated report is ignored rather than moving it back.
 *
 * One exception. When we give up submitting after an hour of errors, we mark the generation Failed
 * without having heard from Pixelforge: an unconfirmed failure. Pixelforge may still have made it (its
 * reply could have been lost), so if it later reports Queued, Processing or Succeeded, that report wins
 * and the real cost is charged. Every other failure is Pixelforge's own answer and stays final.
 */
enum GenerationStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /**
     * Not finished yet: credits are held and the image may still arrive.
     *
     * @return list<self>
     */
    public static function unfinished(): array
    {
        return array_values(array_filter(self::cases(), fn (self $status): bool => ! $status->isFinal()));
    }

    /**
     * Accepted by Pixelforge and not finished: what counts towards its concurrency limit, and what polling
     * watches.
     *
     * @return list<self>
     */
    public static function atProvider(): array
    {
        return [self::Queued, self::Processing];
    }

    public static function fromPixelforge(PixelforgeStatus $status): self
    {
        return match ($status) {
            PixelforgeStatus::Queued => self::Queued,
            PixelforgeStatus::Processing => self::Processing,
            PixelforgeStatus::Succeeded => self::Succeeded,
            PixelforgeStatus::Failed => self::Failed,
        };
    }

    /**
     * Where each status may go next. The single source of truth for every status change.
     *
     * @return list<self>
     */
    public function transitions(): array
    {
        return match ($this) {
            self::Pending => [self::Queued, self::Processing, self::Succeeded, self::Failed],
            self::Queued => [self::Processing, self::Succeeded, self::Failed],
            self::Processing => [self::Succeeded, self::Failed],
            self::Succeeded, self::Failed => [],
        };
    }

    /**
     * @param  bool  $unconfirmedFailure  Whether this is a failure we declared without hearing from Pixelforge.
     */
    public function canTransitionTo(self $next, bool $unconfirmedFailure = false): bool
    {
        if ($unconfirmedFailure && $this === self::Failed) {
            return in_array($next, [self::Queued, self::Processing, self::Succeeded], true);
        }

        return in_array($next, $this->transitions(), true);
    }

    public function isFinal(): bool
    {
        return $this->transitions() === [];
    }
}
