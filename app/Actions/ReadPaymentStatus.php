<?php

namespace App\Actions;

use App\Models\Order;
use App\Payments\PaymentStatus;
use App\Payments\StripePayment;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Asking what happened to the money for one order.
 *
 * **It reads, and that is the whole of it.** Settling an order is
 * `MarkOrderPaid`, reached by the webhook and by a person in the panel, and
 * this must never become a third road to it however plainly Stripe says the
 * payment succeeded. A polled endpoint is the worst possible second writer: it
 * runs on whatever interval a browser felt like, from a request nobody
 * authenticated beyond holding a token.
 *
 * **Two of the three answers cost no round trip**, which matters because a
 * confirmation screen polls. An order already paid is `succeeded` from our own
 * table, and an order with no payment started has nothing at Stripe to ask
 * about.
 *
 * **Nothing here proves Stripe returns any of this.** A substituted client
 * agrees with this code whatever this code does. See
 * .claude/external-services.md.
 */
class ReadPaymentStatus
{
    public function __construct(private readonly StripeClient $stripe) {}

    /**
     * @throws ApiErrorException when Stripe cannot be asked, which the caller
     *                           turns into "we could not find out, ask again"
     *                           rather than into a status nobody was told.
     */
    public function __invoke(Order $order): PaymentStatus
    {
        // Read before Stripe is reached for, so an order settled by hand
        // answers without an intent existing. ~~And the one a gift code paid
        // for.~~ Struck 2 September 2026: a redemption never calls this, and
        // the order behind a redeemed code was paid at Stripe like any other.
        if ($order->isPaid()) {
            return PaymentStatus::settled();
        }

        if ($order->stripe_payment_intent_id === null) {
            return PaymentStatus::nothingCollected();
        }

        return PaymentStatus::atStripe(StripePayment::from(
            $this->stripe->paymentIntents->retrieve($order->stripe_payment_intent_id),
        ));
    }
}
