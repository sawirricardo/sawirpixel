<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\PurchaseCreditsRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\Billing\CreditLedger;
use App\Services\Pricing\Money;
use Brick\Math\RoundingMode;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * "Buy credits": adds a pack of credits to the current organization's balance. No payment is taken;
 * this stands in for a real checkout.
 */
final class CreditPurchaseController
{
    public function __invoke(PurchaseCreditsRequest $request, #[CurrentUser] User $user, CreditLedger $ledger): RedirectResponse
    {
        $organization = $user->currentOrganization;
        abort_unless($organization instanceof Organization, 404);
        Gate::authorize('buyCredits', $organization);

        $purchase = $ledger->purchase($organization, $user, $request->amount(), $request->purchaseKey());

        Inertia::flash('success', sprintf(
            'Added %s in credits to %s. The balance is now %s.',
            Money::format($purchase->amount),
            $organization->name,
            Money::format($purchase->balance_after, RoundingMode::Down),
        ));

        return back();
    }
}
