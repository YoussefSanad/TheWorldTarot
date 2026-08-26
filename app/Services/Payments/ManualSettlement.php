<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Payments\PaymentInstruction;

/**
 * Somebody settles the order by hand in the admin panel.
 *
 * **Not scaffolding, and not a stub to be deleted now Stripe has landed.**
 * Support needs this after launch, for the order that goes wrong in a way the
 * card processor cannot put right, and for the customer who pays by some other
 * means entirely. It is built as something that stays, and it is offered in
 * every environment for that reason.
 *
 * What it bought before Stripe existed: fulfillment, the access grant, order
 * notifications and the whole Viewing Room path got built, tested and walked on
 * staging against something that cannot fail for reasons outside this repo. It
 * also produces a real pass holder, which is what the staging Viewing Room walk
 * had been blocked on since M4.
 *
 * What it does not do is prove anything whatsoever about Stripe.
 */
class ManualSettlement implements PaymentGateway
{
    /**
     * Nothing is collected and there is nowhere to send anybody.
     *
     * The order stays pending until a person marks it paid, which raises the
     * same transition a webhook raises.
     */
    public function begin(Order $order): PaymentInstruction
    {
        return PaymentInstruction::nothingToPay();
    }
}
