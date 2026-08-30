<?php

namespace App\Payments;

use Stripe\PaymentIntent;

/**
 * How far along somebody's payment is, as the confirmation screen reads it.
 *
 * **Stripe's PaymentIntent status, not the order's.** The frontend's mapping
 * from Stripe's seven statuses onto the four outcomes it renders is already
 * written and tested, and answering `pending` or `paid` would put a customer who
 * arrives before the webhook in front of a screen hedging about a payment that
 * plainly succeeded. Section 2 of API_CONTRACT.md carries the same rule from
 * their side.
 *
 * **It reports a payment and never a fulfilment.** Nothing here says a reading
 * has been written or sent, and nothing here may learn to.
 *
 * **The set of values is Stripe's and can grow**, so a status this application
 * has never seen travels through unchanged rather than being translated into
 * something it recognises. The frontend treats an unfamiliar one as "we do not
 * know yet" and polls again.
 */
class PaymentStatus
{
    private function __construct(public readonly string $status) {}

    /**
     * No payment has been started against this order at all.
     *
     * **Answered rather than errored**, and answered with the status Stripe
     * itself gives an intent that nothing has been collected against, because
     * that is exactly what is true and the polling loop already does the right
     * thing with it.
     */
    public static function nothingCollected(): self
    {
        return new self(PaymentIntent::STATUS_REQUIRES_PAYMENT_METHOD);
    }

    /**
     * The order is paid, whatever road the money came down.
     *
     * **One path on the confirmation screen rather than two.** An order settled
     * by hand in the panel has no payment at Stripe behind it, and a gift code
     * redemption will not have one either, so an intent that does not exist is
     * not something the frontend should have to render around.
     */
    public static function settled(): self
    {
        return new self(PaymentIntent::STATUS_SUCCEEDED);
    }

    /**
     * What Stripe currently says about the payment, passed through unchanged.
     */
    public static function atStripe(StripePayment $payment): self
    {
        return new self($payment->status);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return ['status' => $this->status];
    }
}
