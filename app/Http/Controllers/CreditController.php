<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\CreditTransactionType;
use App\Http\Requests\CreditHistoryRequest;
use App\Models\CreditTransaction;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Services\Pricing\Money;
use Brick\Math\BigDecimal;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The current organization's credit history: every purchase, hold, return and charge.
 */
final class CreditController
{
    public const int PER_PAGE = 25;

    public function index(CreditHistoryRequest $request, #[CurrentUser] User $user, CreditLedger $ledger): Response|RedirectResponse
    {
        $organization = $user->currentOrganization;

        if (! $organization instanceof Organization) {
            return to_route('organizations.create');
        }

        Gate::authorize('viewCredits', $organization);

        $type = $request->type();

        $entries = CreditTransaction::query()
            ->where('organization_id', $organization->id)
            ->when($type instanceof CreditTransactionType, fn (Builder $query): Builder => $query->where('type', $type))
            ->with(['user:id,name', 'generation:id,user_id,prompt'])
            ->latest('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (CreditTransaction $entry): array => [
                'id' => $entry->id,
                'type' => $entry->type->value,
                'label' => $entry->type->label(),
                'member' => $entry->user?->name,
                'generation' => $entry->generation === null ? null : [
                    'id' => $entry->generation->id,
                    'prompt' => Str::limit($entry->generation->prompt, 60),
                    'is_yours' => $entry->generation->user_id === $user->id,
                ],
                'amount' => Money::toArray($entry->amount),
                'balance_after' => Money::toArray($entry->balance_after),
                'created_at' => $entry->created_at?->toIso8601String(),
            ]);

        return Inertia::render('credits/Index', [
            'filters' => ['type' => $type?->value],
            'summary' => [
                'balance' => Money::toArray($organization->credit_balance),
                'reserved' => Money::toArray($ledger->reserved($organization)),
                'purchased' => Money::toArray($this->total($organization, CreditTransactionType::Purchase)),
                'charged' => Money::toArray($this->total($organization, CreditTransactionType::Charge)->negated()),
            ],
            'entries' => $entries,
            'canBuy' => Gate::allows('buyCredits', $organization),
        ]);
    }

    /**
     * Exact lifetime total of one kind of entry: a numeric sum in Postgres.
     */
    private function total(Organization $organization, CreditTransactionType $type): BigDecimal
    {
        return Money::ofSum(CreditTransaction::query()
            ->where('organization_id', $organization->id)
            ->where('type', $type)
            ->sum('amount'));
    }
}
