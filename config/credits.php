<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Low Balance Alert
    |--------------------------------------------------------------------------
    |
    | When a charge takes an organization's credits below this amount (USD),
    | the members who can buy credits get a notification.
    |
    */

    'low_balance' => env('CREDITS_LOW_BALANCE', '1.00'),

];
