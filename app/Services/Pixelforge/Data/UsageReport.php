<?php

declare(strict_types=1);

namespace App\Services\Pixelforge\Data;

use Illuminate\Support\Fluent;

/**
 * Pixelforge's GET /v1/usage: what it billed the API key, per UTC day of completion. This is the source
 * of truth for provider costs.
 */
final readonly class UsageReport
{
    /**
     * @param  array<string, UsageDay>  $days  Keyed by date (YYYY-MM-DD).
     */
    public function __construct(
        public string $startDate,
        public string $endDate,
        public array $days,
        public UsageDay $total,
    ) {}

    /**
     * @param  array<array-key, mixed>  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        $data = new Fluent($attributes);
        $days = [];

        foreach ($data->array('data') as $day) {
            $usageDay = UsageDay::fromFluent(new Fluent(is_array($day) ? $day : []));

            if ($usageDay->date !== null) {
                $days[$usageDay->date] = $usageDay;
            }
        }

        return new self(
            startDate: $data->string('start_date')->value(),
            endDate: $data->string('end_date')->value(),
            days: $days,
            total: UsageDay::fromFluent(new Fluent($data->array('total'))),
        );
    }
}
