<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Order;

/**
 * Somebody settles the order by hand in the admin panel.
 *
 * **Not scaffolding, and not a stub to be deleted when Stripe lands.** Support
 * will need this after launch, for the order that goes wrong in a way the card
 * processor cannot put right, and for the customer who pays by some other means
 * entirely. It is worth building as something that stays.
 *
 * What it buys before Stripe exists: fulfillment, the access grant, order
 * notifications and the whole Viewing Room path get built, tested and walked on
 * staging against something that cannot fail for reasons outside this repo. It
 * also produces a real pass holder, which is what the staging Viewing Room walk
 * has been blocked on since M4.
 *
 * What it does not do is prove anything whatsoever about Stripe.
 */
class ManualSettlement implements PaymentGateway
{
    /**
     * Nothing is collected and there is nowhere to send anybody.
     *
     * The order stays pending until a person marks it paid, which raises the
     * same transition a webhook will raise in phase B.
     */
    public function begin(Order $order): ?string
    {
        return null;
    }
}
