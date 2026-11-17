<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\OrgUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_super_admin' => $user->isSuperAdmin(),
                ] : null,
                'organization' => fn (): ?array => $user instanceof User && $user->currentOrganization !== null ? [
                    'id' => $user->currentOrganization->id,
                    'name' => $user->currentOrganization->name,
                ] : null,
                'organizations' => fn (): array => $user instanceof User ? $user->orgUsers()
                    ->with('organization:id,name')
                    ->get()
                    ->map(fn (OrgUser $membership): array => [
                        'id' => $membership->organization_id,
                        'name' => $membership->organization->name ?? '',
                    ])
                    ->sortBy('name')
                    ->values()
                    ->all() : [],
                'canViewMembers' => fn (): bool => $user instanceof User && $user->currentOrganization !== null
                    && Gate::forUser($user)->allows('viewMembers', $user->currentOrganization),
            ],
        ];
    }
}
