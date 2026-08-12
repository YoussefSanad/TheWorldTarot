<?php

namespace App\Contracts;

use App\Models\Order;

/**
 * Collecting money for an order.
 *
 * **The seam is deliberately narrow, and it sits at one moment: an order
 * becomes paid.** Upstream of that is somebody else's business, Stripe's in
 * phase B. Downstream is entirely ours, and has nothing to do with how the
 * money arrived. Putting the boundary anywhere else, at "create a checkout
 * session" for instance, would let one payment provider's model leak into
 * everything that follows and defeat the point of having a boundary.
 *
 * So this interface has one method, and the other direction, learning that an
 * order was paid, is not here at all. That is an event this application raises
 * about itself, in step 7, whether it was raised by a person in the panel or by
 * a webhook later. Both run identical fulfillment.
 *
 * Named in config/payments.php. See .claude/external-services.md, including the
 * rule that binds anything implementing this: a fake proves a caller and never
 * proves an implementation.
 */
interface PaymentGateway
{
    /**
     * Start collecting for an order that has just been placed.
     *
     * Returns where to send the customer next, or **null when there is nowhere
     * to send them** and the order simply waits. Null is not a failure and is
     * the normal answer today.
     *
     * A string rather than something richer because that is all this
     * application currently needs to say. When a provider needs to hand back
     * more, this changes with one implementation and one caller, which is
     * cheaper than guessing now what it will want.
     */
    public function begin(Order $order): ?string;
}
