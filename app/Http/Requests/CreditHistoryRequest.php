<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\CreditTransactionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreditHistoryRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::enum(CreditTransactionType::class)->only([CreditTransactionType::Purchase, CreditTransactionType::Charge])],
        ];
    }

    public function type(): ?CreditTransactionType
    {
        return $this->enum('type', CreditTransactionType::class);
    }
}
