<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\TokenAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A token's note and scopes. Its secret, organization and expiry don't change here.
 */
final class UpdatePersonalAccessTokenRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['distinct', Rule::enum(TokenAbility::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'abilities.required' => 'Choose at least one scope.',
            'abilities.min' => 'Choose at least one scope.',
        ];
    }

    /**
     * @return list<string>
     */
    public function abilityValues(): array
    {
        return array_values(array_filter($this->array('abilities'), is_string(...)));
    }
}
