<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Payments\OrderCannotBeCharged;
use App\Payments\PaymentInstruction;
use Stripe\StripeClient;

/**
 * Taking a card, a wallet or whatever else Stripe accepts, in our own page.
 *
 * **There is no redirect anywhere.** A PaymentIntent is created here, its client
 * secret goes back to the frontend, and the Express Checkout Element and the
 * Payment Element confirm it on the reading page. Stripe has no parameter that
 * pre-selects a wallet on its hosted page, so a redirect could not deliver the
 * design Jennifer paid for.
 *
 * **Nothing here proves Stripe accepts what it sends.** A test against a
 * substituted StripeClient agrees with this class whatever this class does. The
 * authority is the captured signature vector on the webhook and the walk
 * against a deployed environment. See .claude/external-services.md.
 */
class StripeCheckout implements PaymentGateway
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function begin(Order $order): PaymentInstruction
    {
        $this->refuseAnythingButAPendingOrder($order);

        $intent = $this->stripe->paymentIntents->create([
            // **What the customer was shown, captured when they ordered.**
            // Never the catalogue, and never a price held at Stripe. Jennifer
            // edits prices, and an amount read from anywhere else is an amount
            // that can move between the order and the payment.
            'amount' => $order->total_amount,

            // The currency they were browsing in, so nothing converts between
            // the page and their bank. Stripe wants it lowercase.
            'currency' => strtolower($order->currency),

            // How the webhook and the sweep find their way back to this order.
            // A string, because Stripe metadata values are strings.
            'metadata' => ['order_id' => (string) $order->id],

            // Wallets and cards without naming them one by one, which is what
            // lets the Express Checkout Element and the Payment Element decide
            // between them in the page.
            'automatic_payment_methods' => ['enabled' => true],

            // **No receipt_email, deliberately.** OrderPaid is the receipt.
            // Setting this would have Stripe send a second one, and two
            // receipts for one payment is a support conversation.
        ]);

        // **Written back before the secret goes out**, so a payment Stripe
        // accepts is reconcilable even if no notification ever arrives. By the
        // time one is lost there is nothing left to write this from.
        //
        // A retry overwrites it with the newest intent, which is intended: an
        // older intent that somehow succeeded is found through the
        // notification's metadata instead, and both paths reach MarkOrderPaid.
        $order->forceFill(['stripe_payment_intent_id' => $intent->id])->save();

        return PaymentInstruction::clientSecret($intent->client_secret);
    }

    /**
     * **No idempotency key across calls**, and that is on purpose.
     *
     * A second call is meant to mint a fresh intent, because a declined card is
     * a retry rather than a dead end. A duplicated intent from a network retry
     * inside one call is harmless: both carry the same order id, only one can
     * succeed, and MarkOrderPaid refuses the second transition.
     */
    private function refuseAnythingButAPendingOrder(Order $order): void
    {
        if ($order->status !== OrderStatus::Pending) {
            throw OrderCannotBeCharged::itIsNotPending($order);
        }

        // The stored total is the authority on what was charged, and the lines
        // are what it was arrived at from. If they disagree, something wrote
        // one without the other and there is no honest number here.
        if ($order->totalOfLines() !== $order->total_amount) {
            throw OrderCannotBeCharged::totalDisagreesWithItsLines($order);
        }
    }
}
