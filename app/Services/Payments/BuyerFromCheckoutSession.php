<?php

namespace App\Services\Payments;

use App\Payments\BuyerIdentity;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Asking Stripe who paid, for an order that was placed by nobody.
 *
 * **Why this exists at all.** Stripe's hosted page collects the buyer's email
 * *after* the order exists, so from 29 August 2026 an order can be pending and
 * unowned. `payment_intent.succeeded` is what settles it, and a PaymentIntent
 * does not carry `customer_details`. The Session does.
 *
 * **This is why we still do not subscribe to `checkout.session.completed`.**
 * The alternative to this class is a second settlement entry point beside the
 * most safety-critical code here, which is proved against a captured signature
 * vector. Pulling the Session by its intent keeps one road into `MarkOrderPaid`
 * and pays for it with one API call on a queued job that already has six hours
 * of retries. See `docs/adr/0002-the-redirect-case-comes-back.md`.
 *
 * **Nothing here throws.** Settlement is reached from a webhook that Stripe
 * redelivers forever on anything that is not a 2xx, so a Stripe outage while
 * reading this must leave the payment recordable rather than raise. It answers
 * null and the caller decides what that means.
 */
class BuyerFromCheckoutSession
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function forPaymentIntent(string $paymentIntentId): ?BuyerIdentity
    {
        try {
            // **One, because there is one.** A Session creates its intent, so
            // the relationship is one to one in this direction, and a limit of
            // one keeps a wrong assumption from quietly becoming a loop.
            $sessions = $this->stripe->checkout->sessions->all([
                'payment_intent' => $paymentIntentId,
                'limit' => 1,
            ]);
        } catch (ApiErrorException $failed) {
            // Loud, because somebody has been charged and this is the only
            // thing standing between them and their reading.
            Log::error('Stripe could not be asked who paid for an order.', [
                'payment_intent' => $paymentIntentId,
                'reason' => $failed->getMessage(),
            ]);

            return null;
        }

        $session = $sessions->data[0] ?? null;

        if ($session === null) {
            // **Normal for a payment that never had a Session**, which is
            // every payment made before the hosted page and every one a gift
            // code will settle. The caller knows whether it needed one.
            return null;
        }

        $details = $session->customer_details;

        return BuyerIdentity::from(
            $details?->email ?? $session->customer_email,
            $details?->name,
        );
    }
}
