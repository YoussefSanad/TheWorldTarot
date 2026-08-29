<?php

namespace App\Payments;

use App\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * The two things that stop a payment being started for an order at all.
 *
 * **A collaborator both Stripe gateways call**, since 29 August 2026. It was a
 * private method inside `StripeCheckout` while there was one Stripe gateway,
 * and there are two: a hosted Checkout Session and the wallet's own
 * PaymentIntent. The rules are identical on both roads and there is no version
 * of this that should ever differ between them.
 *
 * **A collaborator rather than a shared base class.** A parent would have to
 * hold the `StripeClient` constructor to be worth having, and the two roads are
 * less alike than that would make them look: one builds `price_data` and two
 * return addresses, the other builds an amount and no address at all. The
 * method name survives as the class name, so the docblocks already naming
 * `refuseAnythingButAPendingOrder` stay true.
 *
 * Beside `OrderCannotBeCharged`, which is what it throws.
 *
 * See docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md.
 */
class RefuseAnythingButAPendingOrder
{
    /**
     * **No idempotency key across calls anywhere**, and that is on purpose.
     *
     * A second call is meant to mint a fresh payment, because a declined card
     * is a retry rather than a dead end. A duplicate from a network retry
     * inside one call is harmless: both carry the same order id, only one can
     * succeed, and MarkOrderPaid refuses the second transition.
     *
     * @throws OrderCannotBeCharged
     */
    public function __invoke(Order $order): void
    {
        if (! $order->isPending()) {
            throw OrderCannotBeCharged::itIsNotPending($order);
        }

        // The stored total is the authority on what was charged, and the lines
        // are what it was arrived at from. If they disagree, something wrote
        // one without the other and there is no honest number here.
        //
        // **Beyond what section 3 of the spec asked for, kept deliberately, and
        // made loud on 26 August 2026.** Prices are tax inclusive by decision,
        // so charged_amount equals total_amount and there is no rounding that
        // could produce this. A mismatch is therefore a defect upstream in
        // whatever wrote the order, not a condition to be handled, and refusing
        // quietly would protect the customer while hiding the bug that reached
        // them. Refusing and shouting does both jobs.
        if ($order->totalOfLines() !== $order->total_amount) {
            Log::error('An order was refused because its total disagrees with its lines.', [
                'order_id' => $order->id,
                'total_amount' => $order->total_amount,
                'total_of_lines' => $order->totalOfLines(),
                'currency' => $order->currency,
            ]);

            throw OrderCannotBeCharged::totalDisagreesWithItsLines($order);
        }
    }
}
