<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditTransactionType;
use Database\Factories\CreditTransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry in an organization's credit ledger. Amounts are signed: credits added are positive, spent
 * negative. user_id is the member who bought or spent, when there was one.
 *
 * @property-read int $id
 * @property int $organization_id
 * @property int|null $user_id
 * @property int|null $generation_id
 * @property CreditTransactionType $type
 * @property string $amount
 * @property string $balance_after
 * @property string|null $purchase_key
 */
final class CreditTransaction extends Model
{
    /** @use HasFactory<CreditTransactionFactory> */
    use HasFactory;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'type' => CreditTransactionType::class,
        'amount' => 'decimal:10',
        'balance_after' => 'decimal:10',
    ];
}
