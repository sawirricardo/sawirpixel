<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Organization;
use App\Services\Billing\CreditLedger;
use App\Services\Pricing\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

/**
 * An organization's credit balance, and how much of it is set aside for images still in progress.
 *
 * @property Organization $resource
 */
final class CreditBalanceResource extends JsonApiResource
{
    public function toType(Request $request): string
    {
        return 'credit-balances';
    }

    public function toId(Request $request): string
    {
        return (string) $this->resource->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(Request $request): array
    {
        return [
            'organization' => $this->resource->name,
            'balance' => Money::toArray($this->resource->credit_balance),
            'reserved' => Money::toArray(resolve(CreditLedger::class)->reserved($this->resource)),
        ];
    }
}
