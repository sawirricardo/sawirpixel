<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Generation;
use App\Models\Organization;
use App\Models\OrgUser;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Services\Pricing\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    public const int SIDEBAR_GENERATIONS = 30;

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
            'recentGenerations' => fn (): array => $this->recentGenerations($user),
            'auth' => [
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_super_admin' => $user->isSuperAdmin(),
                ] : null,
                'credits' => fn (): ?array => $user instanceof User && $user->currentOrganization instanceof Organization ? [
                    'balance' => Money::toArray($user->currentOrganization->credit_balance),
                    'reserved' => Money::toArray(resolve(CreditLedger::class)->reserved($user->currentOrganization)),
                    'canBuy' => Gate::forUser($user)->allows('buyCredits', $user->currentOrganization),
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

    /**
     * The sidebar's history: the user's latest generations in their current organization.
     *
     * @return list<array{id: int, prompt: string, status: string, image_url: string|null, created_at: string|null}>
     */
    private function recentGenerations(mixed $user): array
    {
        if (! $user instanceof User || $user->current_organization_id === null) {
            return [];
        }

        return array_values($user->generations()
            ->where('organization_id', $user->current_organization_id)
            ->latest('id')
            ->limit(self::SIDEBAR_GENERATIONS)
            ->get(['id', 'prompt', 'status', 'image_url', 'created_at'])
            ->map(fn (Generation $generation): array => [
                'id' => $generation->id,
                'prompt' => Str::limit($generation->prompt, 80),
                'status' => $generation->status->value,
                'image_url' => $generation->image_url,
                'created_at' => $generation->created_at?->toIso8601String(),
            ])
            ->all());
    }
}
