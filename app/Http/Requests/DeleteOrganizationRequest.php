<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Deleting an organization requires typing its name exactly.
 */
final class DeleteOrganizationRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $user = $this->user();
        $name = $user instanceof User ? $user->currentOrganization?->name : null;

        return [
            // Rule::in compares the whole name, so names containing commas still work.
            'confirm_name' => ['required', 'string', Rule::in([$name])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm_name.in' => "Type the organization's name exactly to confirm.",
        ];
    }
}
