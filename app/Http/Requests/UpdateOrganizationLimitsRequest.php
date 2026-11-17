<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\Pixelforge\ImageQuality;
use App\Enums\Pixelforge\ImageSize;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An organization's generation limits. Empty means no limit. Members must keep at least one quality and
 * one size, so a setting can't stop them generating altogether.
 */
final class UpdateOrganizationLimitsRequest extends FormRequest
{
    public const array SPEND_LIMITS = ['spend_limit_daily', 'spend_limit_monthly', 'member_spend_limit_daily', 'member_spend_limit_monthly'];

    public const array GENERATION_LIMITS = ['generation_limit_daily', 'generation_limit_monthly', 'member_generation_limit_daily', 'member_generation_limit_monthly'];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'member_qualities' => ['required', 'array', 'min:1'],
            'member_qualities.*' => ['distinct', Rule::enum(ImageQuality::class)],
            'member_sizes' => ['required', 'array', 'min:1'],
            'member_sizes.*' => ['distinct', Rule::enum(ImageSize::class)],
        ];

        foreach (self::SPEND_LIMITS as $field) {
            $rules[$field] = ['nullable', 'numeric', 'decimal:0,2', 'min:0.01', 'max:1000000'];
        }

        foreach (self::GENERATION_LIMITS as $field) {
            $rules[$field] = ['nullable', 'integer', 'min:1', 'max:1000000'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'member_qualities.required' => 'Leave members at least one quality.',
            'member_qualities.min' => 'Leave members at least one quality.',
            'member_sizes.required' => 'Leave members at least one size.',
            'member_sizes.min' => 'Leave members at least one size.',
        ];
    }

    /**
     * The validated limits as column values. Allowing every quality or size is stored as no restriction.
     *
     * @return array<string, string|int|list<string>|null>
     */
    public function limits(): array
    {
        $limits = [];

        foreach (self::SPEND_LIMITS as $field) {
            $limits[$field] = $this->filled($field) ? $this->string($field)->value() : null;
        }

        foreach (self::GENERATION_LIMITS as $field) {
            $limits[$field] = $this->filled($field) ? $this->integer($field) : null;
        }

        $qualities = $this->selected('member_qualities', array_map(fn (ImageQuality $quality): string => $quality->value, ImageQuality::cases()));
        $sizes = $this->selected('member_sizes', array_map(fn (ImageSize $size): string => $size->value, ImageSize::cases()));

        return [...$limits, 'member_qualities' => $qualities, 'member_sizes' => $sizes];
    }

    /**
     * @param  list<string>  $all
     * @return list<string>|null
     */
    private function selected(string $field, array $all): ?array
    {
        $selected = array_values(array_intersect($all, array_filter($this->array($field), is_string(...))));

        return count($selected) === count($all) ? null : $selected;
    }
}
