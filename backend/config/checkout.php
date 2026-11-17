<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Checkout Admission
    |--------------------------------------------------------------------------
    |
    | The kill switch and the minimum account age gate the immediate online
    | checkout regardless of the payment provider.
    |
    | FILTER_NULL_ON_FAILURE keeps an unparseable value distinguishable from a
    | deliberate `false`: `CHECKOUT_ENABLED=flase` must not be coerced into
    | `false` without a trace. The kill switch treats every non-boolean as
    | invalid, fails closed and logs it (CheckoutKillSwitch::enabled()).
    |
    */

    'enabled' => filter_var(env('CHECKOUT_ENABLED', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE),

    'new_account_hours' => max(0, (int) env('CHECKOUT_NEW_ACCOUNT_HOURS', 24)),

];
