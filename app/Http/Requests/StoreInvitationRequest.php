<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class StoreInvitationRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role_id' => ['required', 'integer', Rule::exists(Role::class, 'id')->where('organization_id', $this->organizationId())],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->has('email')) {
                    return;
                }

                $alreadyMember = User::query()
                    ->where('email', mb_strtolower($this->string('email')->value()))
                    ->whereHas('orgUsers', fn (Builder $query): Builder => $query->where('organization_id', $this->organizationId()))
                    ->exists();

                if ($alreadyMember) {
                    $validator->errors()->add('email', 'That person is already a member.');
                }
            },
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
