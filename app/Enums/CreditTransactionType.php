<?php

declare(strict_types=1);

namespace App\Enums;

enum CreditTransactionType: string
{
    /** Credits bought by the user (a fake purchase; no real payment is taken). */
    case Purchase = 'purchase';

    /** The most a generation can cost, reserved when it is submitted. */
    case Hold = 'hold';

    /** A generation's hold returned once it finished, succeeded or failed. */
    case HoldRelease = 'hold_release';

    /** What a succeeded generation actually cost. */
    case Charge = 'charge';

    public function label(): string
    {
        return match ($this) {
            self::Purchase => 'Purchase',
            self::Hold => 'Reserved',
            self::HoldRelease => 'Returned',
            self::Charge => 'Charged',
        };
    }
}
