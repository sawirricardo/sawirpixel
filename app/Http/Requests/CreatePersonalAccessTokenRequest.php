<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Concerns\ValidatesTokenExpiration;
use App\Enums\TokenAbility;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new personal access token: a note to recognise it by, the organization it acts in (one the user
 * belongs to), its scopes and how long it lasts.
 */
final class CreatePersonalAccessTokenRequest extends FormRequest
{
    use ValidatesTokenExpiration;

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();

        return [
            'name' => ['required', 'string', 'max:100'],
            'organization_id' => ['required', 'integer', Rule::in($user instanceof User ? $user->orgUsers()->pluck('organization_id')->all() : [])],
            'abilities' => ['required', 'array', 'min:1'],
            'abilities.*' => ['distinct', Rule::enum(TokenAbility::class)],
            'expiration' => $this->expirationRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Give the token a note, so you can recognise it later.',
            'organization_id.in' => "Choose an organization you're a member of.",
            'abilities.required' => 'Choose at least one scope.',
            'abilities.min' => 'Choose at least one scope.',
        ];
    }

    public function organization(): Organization
    {
        return Organization::query()->findOrFail($this->integer('organization_id'));
    }

    /**
     * @return list<TokenAbility>
     */
    public function abilities(): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $value): ?TokenAbility => is_string($value) ? TokenAbility::tryFrom($value) : null,
            $this->array('abilities'),
        )));
    }
}
