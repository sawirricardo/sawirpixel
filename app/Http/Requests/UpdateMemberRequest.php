<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateMemberRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'role_id' => ['required', 'integer', Rule::exists(Role::class, 'id')->where('organization_id', $this->organizationId())],
        ];
    }

    public function role(): Role
    {
        return Role::query()->where('organization_id', $this->organizationId())->findOrFail($this->integer('role_id'));
    }

    private function organizationId(): ?int
    {
        $user = $this->user();

        return $user instanceof User ? $user->current_organization_id : null;
    }
}
