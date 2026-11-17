<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * A UTC date range, with the same defaults and limits as Pixelforge's usage report: the last 30 days
 * ending today, at most 92 days.
 */
final class ProfitReportRequest extends FormRequest
{
    public const int DEFAULT_DAYS = 30;

    public const int MAX_DAYS = 92;

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->startDate()->diffInDays($this->endDate()) + 1 > self::MAX_DAYS) {
                    $validator->errors()->add('start_date', sprintf('The report can cover at most %d days.', self::MAX_DAYS));
                }
            },
        ];
    }

    public function endDate(): CarbonImmutable
    {
        return $this->filled('end_date')
            ? CarbonImmutable::createFromFormat('!Y-m-d', $this->string('end_date')->value(), 'UTC') ?: CarbonImmutable::now('UTC')->startOfDay()
            : CarbonImmutable::now('UTC')->startOfDay();
    }

    public function startDate(): CarbonImmutable
    {
        return $this->filled('start_date')
            ? CarbonImmutable::createFromFormat('!Y-m-d', $this->string('start_date')->value(), 'UTC') ?: $this->endDate()
            : $this->endDate()->subDays(self::DEFAULT_DAYS - 1);
    }
}
