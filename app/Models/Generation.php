<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;
use App\Exceptions\InvalidStatusTransitionException;
use Carbon\CarbonInterface;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Attributes\Boot;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property int $organization_id
 * @property int $user_id
 * @property int|null $parent_id The version this one continues from.
 * @property int|null $thread_id The id of the thread's first generation.
 * @property GenerationStatus $status
 * @property string|null $pixelforge_id
 * @property string $idempotency_key
 * @property string $prompt
 * @property ImageSize $size
 * @property ImageQuality $quality
 * @property list<string> $reference_images
 * @property string $estimated_charge_min
 * @property string $estimated_charge_max
 * @property string|null $provider_cost
 * @property string|null $charge
 * @property array<string, int>|null $usage
 * @property string|null $image_url
 * @property CarbonInterface|null $image_url_expires_at
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonInterface|null $submitted_at
 * @property CarbonInterface|null $completed_at
 */
final class Generation extends Model
{
    /** @use HasFactory<GenerationFactory> */
    use HasFactory;

    /**
     * The error code of a failure we declared ourselves after giving up on submitting, without hearing
     * from Pixelforge. Pixelforge can still overturn it; see GenerationStatus.
     */
    public const string UNCONFIRMED_FAILURE = 'submission_failed';

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'status' => GenerationStatus::class,
        'size' => ImageSize::class,
        'quality' => ImageQuality::class,
        'reference_images' => 'array',
        'estimated_charge_min' => 'decimal:10',
        'estimated_charge_max' => 'decimal:10',
        'provider_cost' => 'decimal:9',
        'charge' => 'decimal:10',
        'usage' => 'array',
        'image_url_expires_at' => 'datetime',
        'submitted_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Enforce the status machine on every Eloquent save: an illegal move (say, out of a final state)
     * throws instead of quietly corrupting billing. Conditional query-builder updates, which
     * SubmitGeneration uses as its concurrency guard, don't fire model events and check the current
     * status in their WHERE clause instead.
     */
    #[Boot]
    public static function guardStatusTransitions(): void
    {
        self::updating(function (Generation $generation): void {
            $from = $generation->getOriginal('status');
            $unconfirmedFailure = $from === GenerationStatus::Failed && $generation->getOriginal('error_code') === self::UNCONFIRMED_FAILURE;

            if ($generation->isDirty('status') && $from instanceof GenerationStatus && ! $from->canTransitionTo($generation->status, $unconfirmedFailure)) {
                throw InvalidStatusTransitionException::between('A generation', $from, $generation->status);
            }
        });
    }

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        // Generations outlive a deleted organization as billing records.
        return $this->belongsTo(Organization::class)->withTrashed();
    }

    public function isUnconfirmedFailure(): bool
    {
        return $this->status === GenerationStatus::Failed && $this->error_code === self::UNCONFIRMED_FAILURE;
    }

    /**
     * Whether a report from Pixelforge may move it to this status: forward only, except that an
     * unconfirmed failure may still be overturned.
     */
    public function canMoveTo(GenerationStatus $next): bool
    {
        return $this->status->canTransitionTo($next, $this->isUnconfirmedFailure());
    }

    /**
     * Whether its image can still be used to continue from: Pixelforge's links expire after a day.
     */
    public function canBeContinued(): bool
    {
        return $this->status === GenerationStatus::Succeeded
            && $this->image_url !== null
            && ($this->image_url_expires_at === null || $this->image_url_expires_at->isFuture());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
