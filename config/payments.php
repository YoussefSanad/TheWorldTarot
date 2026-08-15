<?php

use App\Services\Payments\ManualSettlement;

return [

    /*
    |--------------------------------------------------------------------------
    | How money is collected
    |--------------------------------------------------------------------------
    |
    | Structure declared in code and arriving by deploy, per CLAUDE.md section 4,
    | and the same shape as config/reveal.php. Swapping how payment works is a
    | class implementing App\Contracts\PaymentGateway and this one line.
    |
    | Today nothing is collected: an order waits until somebody settles it in the
    | admin panel. That is deliberate rather than unfinished. It lets everything
    | downstream of payment be built and proved against something that cannot
    | fail for reasons outside this repo, and it stays afterwards for support.
    |
    | Stripe arrives in phase B, and cannot start until VAT ownership and gift
    | code semantics are settled, since between them they decide whether integer
    | cents remain sufficient. See CLAUDE.md section 8.
    |
    */

    'gateway' => ManualSettlement::class,

];
