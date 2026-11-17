<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\GenerationStatus;
use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;
use Carbon\CarbonInterface;
use Database\Factories\GenerationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read int $id
 * @property int $organization_id
 * @property int $user_id
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
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
