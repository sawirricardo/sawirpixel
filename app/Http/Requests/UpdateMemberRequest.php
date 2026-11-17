<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The complete set of roles a member should have; at least one.
 */
final class UpdateMemberRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'distinct', Rule::exists(Role::class, 'id')->where('organization_id', $this->organizationId())],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role_ids.required' => 'A member needs at least one role.',
        ];
    }

    /**
     * @return list<Role>
     */
    public function roles(): array
    {
        return array_values(Role::query()
            ->where('organization_id', $this->organizationId())
            ->whereIn('id', $this->array('role_ids'))
            ->get()
            ->all());
    }

    private function organizationId(): ?int
    {
        $user = $this->user();

        return $user instanceof User ? $user->current_organization_id : null;
    }
}
