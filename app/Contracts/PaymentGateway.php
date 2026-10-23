<?php

namespace App\Contracts;

use App\Enums\ProductKey;
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
 * order was paid, is not here at all. That is `MarkOrderPaid`, which ~~a person in
 * the panel and~~ a webhook ~~both~~ reaches. **Struck 22 October 2026: the panel
 * cannot mark an order paid**, so the webhook and the sweep are the only roads.
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
     *
     * @param  ProductKey|null  $returnTo  Which reading page the customer came
     *                                     from, so a method that sends them
     *                                     away can send them back to it. **An
     *                                     enum rather than a path or a URL**:
     *                                     its values are already the frontend's
     *                                     reading-page slugs, so no string a
     *                                     caller typed ever reaches an address.
     *                                     A method that collects in the page
     *                                     has nowhere to return from and
     *                                     ignores it.
     */
    public function begin(Order $order, ?ProductKey $returnTo = null): PaymentInstruction;
}
