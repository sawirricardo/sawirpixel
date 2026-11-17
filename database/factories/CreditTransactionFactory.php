<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Prefer CreditLedger in tests: it keeps the cached balance in step with the ledger.
 *
 * @extends Factory<CreditTransaction>
 */
final class CreditTransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'type' => CreditTransactionType::Purchase,
            'amount' => '10',
            'balance_after' => '10',
        ];
    }
}
