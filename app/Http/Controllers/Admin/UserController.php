<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\AdminUserIndexRequest;
use App\Models\Generation;
use App\Models\OrgUser;
use App\Models\Permission;
use App\Models\User;
use App\Services\Pricing\Money;
use App\Services\Reporting\UserSpend;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only directory of every account, for operators.
 */
final class UserController
{
    public const int PER_PAGE = 20;

    public const int RECENT_GENERATIONS = 20;

    public function index(AdminUserIndexRequest $request, UserSpend $spend): Response
    {
        $search = $request->search();

        $users = User::query()
            ->when($search !== '', fn (Builder $query): Builder => $query->where(
                fn (Builder $query): Builder => $query
                    ->whereLike('name', "%{$search}%")
                    ->orWhereLike('email', "%{$search}%"),
            ))
            ->withCount(['orgUsers', 'generations'])
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        $tallies = $spend->forUsers(array_values(array_map(fn (User $user): int => $user->id, $users->items())));

        return Inertia::render('admin/users/Index', [
            'filters' => ['search' => $search],
            'users' => $users->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_super_admin' => $user->isSuperAdmin(),
                'organizations' => $user->org_users_count,
                'generations' => $user->generations_count,
                'charged' => $tallies[$user->id]->toArray()['charged'],
                'joined_at' => $user->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * The user is looked up here rather than through route model binding, so the admin gate on the
     * route group runs first and non-operators can't probe which user ids exist.
     */
    public function show(string $user, UserSpend $spend): Response
    {
        $user = User::query()->findOrFail($user);

        $memberships = $user->orgUsers()
            ->with(['organization:id,name', 'roles:id,name', 'permissions'])
            ->oldest('id')
            ->get();

        return Inertia::render('admin/users/Show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_super_admin' => $user->isSuperAdmin(),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'joined_at' => $user->created_at?->toIso8601String(),
                'current_organization_id' => $user->current_organization_id,
            ],
            'totals' => $spend->forUsers([$user->id])[$user->id]->toArray(),
            'memberships' => $memberships->map(fn (OrgUser $membership): array => [
                'organization_id' => $membership->organization_id,
                'organization' => $membership->organization->name ?? '',
                'roles' => $membership->roles->pluck('name')->all(),
                'permissions' => $membership->permissions->map(fn (Permission $permission): string => $permission->name->label())->all(),
                'joined_at' => $membership->created_at?->toIso8601String(),
            ]),
            'generations' => $user->generations()
                ->with('organization:id,name')
                ->latest('id')
                ->limit(self::RECENT_GENERATIONS)
                ->get()
                ->map(fn (Generation $generation): array => [
                    'id' => $generation->id,
                    'prompt' => $generation->prompt,
                    'organization' => $generation->organization->name ?? '',
                    'status' => $generation->status->value,
                    'charge' => $generation->charge === null ? null : Money::toArray($generation->charge),
                    'estimated_charge' => ['min' => Money::toArray($generation->estimated_charge_min), 'max' => Money::toArray($generation->estimated_charge_max)],
                    'created_at' => $generation->created_at?->toIso8601String(),
                ]),
        ]);
    }
}
