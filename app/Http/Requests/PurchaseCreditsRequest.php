<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Brick\Math\BigDecimal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A fake credit purchase: one of the fixed packs, plus a key the browser generates per attempt so a
 * double-click or retry can't buy twice.
 */
final class PurchaseCreditsRequest extends FormRequest
{
    /** Pack sizes in USD. */
    public const array PACKS = [5, 10, 25, 50];

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'pack' => ['required', 'integer', Rule::in(self::PACKS)],
            'purchase_key' => ['required', 'uuid'],
        ];
    }

    public function amount(): BigDecimal
    {
        return BigDecimal::of($this->integer('pack'));
    }

    public function purchaseKey(): string
    {
        return $this->string('purchase_key')->value();
    }
}
