<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Generation;
use App\Models\Organization;
use App\Models\OrgUser;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Services\Pricing\Money;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    public const int SIDEBAR_GENERATIONS = 30;

    public const int RECENT_NOTIFICATIONS = 8;

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
            'notifications' => fn (): ?array => $user instanceof User ? $this->notifications($user) : null,
            'auth' => [
                'user' => $user instanceof User ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_super_admin' => $user->isSuperAdmin(),
                    'email_verified' => $user->hasVerifiedEmail(),
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
     * The bell in the header: how many are unread and the latest few.
     *
     * @return array{unread: int, recent: list<array{id: string, kind: string, title: string, body: string, read: bool, created_at: string|null}>}
     */
    private function notifications(User $user): array
    {
        $text = fn (mixed $value): string => is_string($value) ? $value : '';

        return [
            'unread' => $user->unreadNotifications()->count(),
            'recent' => array_values($user->notifications()
                ->limit(self::RECENT_NOTIFICATIONS)
                ->get()
                ->map(fn (DatabaseNotification $notification): array => [
                    'id' => $notification->id,
                    'kind' => $text($notification->data['kind'] ?? null),
                    'title' => $text($notification->data['title'] ?? null),
                    'body' => $text($notification->data['body'] ?? null),
                    'read' => $notification->read_at !== null,
                    'created_at' => $notification->created_at?->toIso8601String(),
                ])
                ->all()),
        ];
    }

    /**
     * The sidebar's history: the user's latest threads in their current organization, newest activity
     * first. Each is titled by its first prompt and links to its newest version.
     *
     * @return list<array{id: int, prompt: string, status: string, versions: int, branches: int, created_at: string|null}>
     */
    private function recentGenerations(mixed $user): array
    {
        if (! $user instanceof User || $user->current_organization_id === null) {
            return [];
        }

        $mine = fn () => Generation::query()->whereBelongsTo($user)->where('organization_id', $user->current_organization_id);

        $latest = $mine()
            ->whereIn('id', $mine()->selectRaw('max(id)')->groupBy('thread_id'))
            ->latest('id')
            ->limit(self::SIDEBAR_GENERATIONS)
            ->get(['id', 'thread_id', 'status', 'created_at']);

        $threadIds = $latest->pluck('thread_id')->all();
        $firstPrompts = $mine()->whereIn('id', $threadIds)->pluck('prompt', 'id');
        $versions = $mine()->whereIn('thread_id', $threadIds)->selectRaw('thread_id, count(*) as versions')->groupBy('thread_id')->pluck('versions', 'thread_id');
        // A branch ends at a version nobody has continued from; more than one means the thread branches.
        $branches = $mine()
            ->whereIn('thread_id', $threadIds)
            ->whereNotExists(fn (QueryBuilder $query): QueryBuilder => $query->selectRaw('1')->from('generations as follow_ups')->whereColumn('follow_ups.parent_id', 'generations.id'))
            ->selectRaw('thread_id, count(*) as branches')
            ->groupBy('thread_id')
            ->pluck('branches', 'thread_id');

        return array_values($latest->map(fn (Generation $generation): array => [
            'id' => $generation->id,
            'prompt' => Str::limit(is_string($firstPrompts[$generation->thread_id] ?? null) ? $firstPrompts[$generation->thread_id] : '', 80),
            'status' => $generation->status->value,
            'versions' => is_numeric($versions[$generation->thread_id] ?? null) ? (int) $versions[$generation->thread_id] : 1,
            'branches' => is_numeric($branches[$generation->thread_id] ?? null) ? (int) $branches[$generation->thread_id] : 1,
            'created_at' => $generation->created_at?->toIso8601String(),
        ])->all());
    }
}
