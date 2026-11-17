<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\CreditTransactionType;
use App\Exceptions\CreditLedgerConflictException;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\Organization;
use App\Models\User;
use App\Services\Pricing\Money;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Every change to an organization's credits goes through here: one ledger row per change, and the
 * cached organizations.credit_balance updated in the same transaction while the organization's row is
 * locked. Balance arithmetic is exact (brick/math in PHP); totals are exact numeric sums in Postgres.
 *
 * Lock order is always generation, then organization, to avoid deadlocks with result handling.
 */
final readonly class CreditLedger
{
    public function __construct(private LowBalanceAlert $lowBalanceAlert) {}

    /**
     * Add purchased credits. Replaying the same purchase key returns the original purchase: the unique
     * (organization, purchase key) index rejects the second insert, rather than a check-then-insert that
     * concurrent requests could both pass.
     */
    public function purchase(Organization $organization, User $buyer, BigDecimal $amount, string $purchaseKey): CreditTransaction
    {
        try {
            return DB::transaction(fn (): CreditTransaction => $this->record(
                $this->lockOrganization($organization->id),
                CreditTransactionType::Purchase,
                $amount,
                $buyer->id,
                purchaseKey: $purchaseKey,
            ));
        } catch (CreditLedgerConflictException $creditLedgerConflictException) {
            return CreditTransaction::query()
                ->where('organization_id', $organization->id)
                ->where('purchase_key', $purchaseKey)
                ->first() ?? throw $creditLedgerConflictException;
        }
    }

    /**
     * Reserve the most a generation can cost. The caller must hold the lock on the organization.
     */
    public function hold(Organization $lockedOrganization, Generation $generation, BigDecimal $amount): void
    {
        $this->record($lockedOrganization, CreditTransactionType::Hold, $amount->negated(), $generation->user_id, $generation->id);
    }

    /**
     * Settle a finished generation: return its hold and, if it succeeded, charge what it actually cost.
     * Safe to call more than once; each part is recorded at most once. A generation submitted before
     * credits existed has no hold and is simply charged.
     */
    public function settle(Generation $generation, ?BigDecimal $charge): void
    {
        DB::transaction(function () use ($generation, $charge): void {
            $organization = $this->lockOrganization($generation->organization_id);
            $entries = CreditTransaction::query()
                ->where('generation_id', $generation->id)
                ->get()
                ->keyBy(fn (CreditTransaction $entry): string => $entry->type->value);

            $hold = $entries->get(CreditTransactionType::Hold->value);

            if ($hold instanceof CreditTransaction && ! $entries->has(CreditTransactionType::HoldRelease->value)) {
                $this->record($organization, CreditTransactionType::HoldRelease, BigDecimal::of($hold->amount)->negated(), $generation->user_id, $generation->id);
            }

            if ($charge instanceof BigDecimal && ! $entries->has(CreditTransactionType::Charge->value)) {
                $before = BigDecimal::of($organization->credit_balance);
                $this->record($organization, CreditTransactionType::Charge, $charge->negated(), $generation->user_id, $generation->id);
                $this->lowBalanceAlert->afterCharge($organization, $before, BigDecimal::of($organization->credit_balance));
            }
        });
    }

    /**
     * Lock and return a fresh copy of the organization, so its balance can't change until the
     * transaction ends.
     */
    public function lockOrganization(int $organizationId): Organization
    {
        // withTrashed: a late result for a deleted organization's generation must still settle.
        return Organization::withTrashed()->whereKey($organizationId)->lockForUpdate()->sole();
    }

    /**
     * Credits set aside by generations that haven't finished yet.
     */
    public function reserved(Organization $organization): BigDecimal
    {
        // Holds are negative entries; a hold still counts until its generation has a release.
        $holds = CreditTransaction::query()
            ->where('organization_id', $organization->id)
            ->where('type', CreditTransactionType::Hold)
            ->whereNotExists(fn (QueryBuilder $releases): QueryBuilder => $releases
                ->selectRaw('1')
                ->from('credit_transactions as releases')
                ->whereColumn('releases.generation_id', 'credit_transactions.generation_id')
                ->where('releases.type', CreditTransactionType::HoldRelease->value))
            ->sum('amount');

        return Money::ofSum($holds)->negated();
    }

    private function record(Organization $lockedOrganization, CreditTransactionType $type, BigDecimal $amount, ?int $userId, ?int $generationId = null, ?string $purchaseKey = null): CreditTransaction
    {
        $balance = BigDecimal::of($lockedOrganization->credit_balance)->plus($amount);

        try {
            $entry = CreditTransaction::query()->create([
                'organization_id' => $lockedOrganization->id,
                'user_id' => $userId,
                'generation_id' => $generationId,
                'type' => $type,
                'amount' => (string) $amount,
                'balance_after' => (string) $balance,
                'purchase_key' => $purchaseKey,
            ]);
        } catch (UniqueConstraintViolationException $uniqueConstraintViolationException) {
            throw CreditLedgerConflictException::from($uniqueConstraintViolationException);
        }

        $lockedOrganization->credit_balance = (string) $balance;
        $lockedOrganization->save();

        return $entry;
    }
}
