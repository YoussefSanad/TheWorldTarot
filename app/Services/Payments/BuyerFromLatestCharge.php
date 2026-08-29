<?php

namespace App\Services\Payments;

use App\Payments\BuyerIdentity;
use Illuminate\Support\Facades\Log;
use Stripe\Charge;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Asking Stripe who paid, for a wallet payment that has no Checkout Session.
 *
 * **Why this exists at all.** `BuyerFromCheckoutSession` beside it lists
 * Sessions by payment intent, and a wallet PaymentIntent has no Session, so
 * that list comes back empty for every wallet payment ever taken. Its docblock
 * already says as much, correctly. Without a second source the order would be
 * left pending on a customer who has been charged, and `ReconcileOrders` would
 * come back to it and hit the same empty list forever. See
 * docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md.
 *
 * **A second class rather than a second lookup on the first one.** Teaching
 * `BuyerFromCheckoutSession` to read a charge would falsify its docblock while
 * keeping the file, which is exactly the defect
 * `tests/Feature/CommentIntegrityTest.php` was written to fail a build over.
 *
 * **The source is the intent's own charge**, whose `billing_details` carry the
 * email and the name the wallet collected when the element is mounted with
 * `emailRequired: true`. `BuyerIdentity::from()` needs no change: its docblock
 * already allows for a payment made by any route that is not a hosted Checkout
 * Session. The value object was written for this.
 *
 * **What is assumed here and is not proved anywhere.** That a real wallet
 * populates `latest_charge.billing_details.email`. A substituted `StripeClient`
 * agrees with whatever this file does, and only a real wallet on a real device
 * settles it. That proof is issue #44, and if it turns out the email does not
 * arrive, ADR 0003 records why server-side confirmation through a
 * ConfirmationToken was not taken first. See .claude/external-services.md.
 *
 * **Nothing here throws**, exactly as its neighbour does not. Settlement is
 * reached from a webhook that Stripe redelivers forever on anything that is not
 * a 2xx, so a Stripe outage while reading this must leave the payment
 * recordable rather than raise. It answers null and the caller decides what
 * that means.
 */
class BuyerFromLatestCharge
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function forPaymentIntent(string $paymentIntentId): ?BuyerIdentity
    {
        try {
            // **The expansion is the whole call.** An unexpanded
            // `latest_charge` is a string id and carries no billing details at
            // all, so a retrieve without it would answer nobody for a payment
            // that named somebody. One request rather than two, because the
            // charge is the only reason this asks.
            $intent = $this->stripe->paymentIntents->retrieve(
                $paymentIntentId,
                ['expand' => ['latest_charge']],
            );
        } catch (ApiErrorException $failed) {
            // Loud, because somebody has been charged and this is the only
            // thing standing between them and their reading.
            Log::error('Stripe could not be asked about the charge that paid for an order.', [
                'payment_intent' => $paymentIntentId,
                'reason' => $failed->getMessage(),
            ]);

            return null;
        }

        $charge = $intent->latest_charge;

        if (! $charge instanceof Charge) {
            // **Null until confirmation is attempted**, which is normal for an
            // intent nobody ever paid. The caller knows whether it expected one.
            return null;
        }

        $details = $charge->billing_details;

        return BuyerIdentity::from($details?->email, $details?->name);
    }
}
