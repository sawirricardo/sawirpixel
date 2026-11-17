<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Illuminate\Http\Request;
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
            ],
        ];
    }
}
