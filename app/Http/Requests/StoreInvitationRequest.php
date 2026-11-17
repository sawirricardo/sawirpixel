<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Actions\GrantableAccess;
use App\Enums\Permission;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * An invitation's email, one or more of the organization's roles, and optional extra permissions.
 * The inviter can only hand out roles and permissions they could grant themselves.
 */
final class StoreInvitationRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', 'distinct', Rule::exists(Role::class, 'id')->where('organization_id', $this->organizationId())],
            'permissions' => ['array'],
            'permissions.*' => ['distinct', Rule::enum(Permission::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role_ids.required' => 'Choose at least one role.',
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

                $alreadyMember = User::query()
                    ->where('email', mb_strtolower($this->string('email')->value()))
                    ->whereHas('orgUsers', fn (Builder $query): Builder => $query->where('organization_id', $this->organizationId()))
                    ->exists();

                if ($alreadyMember) {
                    $validator->errors()->add('email', 'That person is already a member.');
                }

                $this->ensureInviterCanGrant($validator);
            },
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

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return array_values(array_filter(array_map(
            fn (mixed $value): ?Permission => is_string($value) ? Permission::tryFrom($value) : null,
            $this->array('permissions'),
        )));
    }

    private function ensureInviterCanGrant(Validator $validator): void
    {
        $user = $this->user();
        $organization = $user instanceof User ? $user->currentOrganization : null;

        // The roles already validated as belonging to the current organization, so both exist here.
        throw_unless($user instanceof User && $organization instanceof Organization, LogicException::class, 'An invitation needs an inviter with a current organization.');

        $access = resolve(GrantableAccess::class);
        $grantableRoleIds = array_map(fn (Role $role): int => $role->id, $access->roles($user, $organization));

        foreach ($this->roles() as $role) {
            if (! in_array($role->id, $grantableRoleIds, true)) {
                $validator->errors()->add('role_ids', sprintf("You can't grant the %s role.", $role->name));
            }
        }

        $grantablePermissions = $access->permissions($user, $organization);

        foreach ($this->permissions() as $permission) {
            if (! in_array($permission, $grantablePermissions, true)) {
                $validator->errors()->add('permissions', sprintf("You can't grant a permission you don't have: %s.", mb_strtolower($permission->label())));
            }
        }
    }

    private function organizationId(): ?int
    {
        $user = $this->user();

        return $user instanceof User ? $user->current_organization_id : null;
    }
}
