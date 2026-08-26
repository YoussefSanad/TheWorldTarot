<?php

namespace App\Contracts;

use App\Models\Order;
use App\Payments\PaymentInstruction;

/**
 * Collecting money for an order.
 *
 * **The seam is deliberately narrow, and it sits at one moment: an order
 * becomes paid.** Upstream of that is somebody else's business, Stripe's. What
 * is downstream is entirely ours, and has nothing to do with how the money
 * arrived. Putting the boundary anywhere else, at "create a checkout session"
 * for instance, would let one payment provider's model leak into everything
 * that follows and defeat the point of having a boundary.
 *
 * So this interface has one method, and the other direction, learning that an
 * order was paid, is not here at all. That is `MarkOrderPaid`, which a person in
 * the panel and a webhook both reach, so fulfillment cannot come to differ
 * between them.
 *
 * **Which implementation runs is chosen per payment**, not per environment: the
 * checkout design puts four buttons on the reading page and the customer picks.
 * The methods this environment offers are named in config/payments.php and
 * resolved by App\Services\Payments\PaymentMethods.
 *
 * See .claude/external-services.md, including the rule that binds anything
 * implementing this: a fake proves a caller and never proves an implementation.
 */
interface PaymentGateway
{
    /**
     * Start collecting for an order, and say what the customer does next.
     *
     * Called again for an order that is still pending, deliberately, because a
     * customer who abandoned a checkout and came back, or whose card was
     * declined, retries against the order they already have rather than
     * building a new one.
     */
    public function begin(Order $order): PaymentInstruction;
}
