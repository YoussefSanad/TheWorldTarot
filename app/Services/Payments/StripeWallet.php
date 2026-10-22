<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\ProductKey;
use App\Models\Order;
use App\Payments\PaymentInstruction;
use App\Payments\RefuseAnythingButAPendingOrder;
use Stripe\StripeClient;

/**
 * Apple Pay and Google Pay, in a sheet the customer's own device opens.
 *
 * **A PaymentIntent, and its client secret goes back to the page.** The Express
 * Checkout Element is an iframe the frontend mounts on the reading page and
 * confirms against this secret. A hosted Checkout Session is an address you
 * send a browser to, and no parameter reconciles the two, which is why this is
 * a second gateway rather than a flag on `StripeCheckout`. The card button
 * still goes to the hosted page and always will.
 *
 * This is what `StripeCheckout` was at `94bf99f`, before `1fd46f6` replaced it,
 * and its reasoning is largely that code's. Decided in
 * docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md, which amends
 * docs/adr/0002-the-redirect-case-comes-back.md rather than reversing it.
 *
 * **Settlement is untouched by any of this.** A wallet payment emits
 * `payment_intent.succeeded` keyed by `metadata.order_id`, exactly as the
 * hosted road does, so the webhook, `SettleStripePayment`, `RecordStripePayment`
 * and `MarkOrderPaid` never learn which button was pressed. We still do not
 * subscribe to `checkout.session.completed`.
 *
 * **What this road does change is who paid.** A wallet PaymentIntent has no
 * Checkout Session, so there is no `customer_details` to read, and identity
 * comes from the intent's own charge instead. That is
 * `App\Services\Payments\BuyerFromLatestCharge`, and shipping this class
 * without it would be shipping a way to take money and deliver nothing.
 *
 * **Nothing here proves Stripe accepts what it sends**, and nothing here proves
 * a wallet sheet ever opens. A test against a substituted StripeClient agrees
 * with this class whatever this class does. The authority is the captured
 * signature vector on the webhook and the walk against a deployed environment.
 * See .claude/external-services.md.
 */
class StripeWallet implements PaymentGateway
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly RefuseAnythingButAPendingOrder $refuseAnythingButAPendingOrder,
    ) {}

    /**
     * @param  ProductKey|null  $returnTo  Nothing to this method. The customer
     *                                     never leaves the reading page, so
     *                                     there is nowhere to come back from.
     */
    public function begin(Order $order, ?ProductKey $returnTo = null): PaymentInstruction
    {
        ($this->refuseAnythingButAPendingOrder)($order);

        $intent = $this->stripe->paymentIntents->create([
            // **What the customer was shown, captured when they ordered.**
            // Never the catalogue, and never a price held at Stripe. Jennifer
            // edits prices, and an amount read from anywhere else is an amount
            // that can move between the order and the payment.
            'amount' => $order->total_amount,

            // The currency they were browsing in, so nothing converts between
            // the page and their bank. Stripe wants it lowercase.
            'currency' => strtolower($order->currency),

            // How the webhook and the sweep find their way back to this order,
            // and on this road it sits directly on the intent rather than being
            // carried to it through a Session's `payment_intent_data`. A string,
            // because Stripe metadata values are strings.
            'metadata' => ['order_id' => (string) $order->id],

            // **Card alone, named explicitly, rather than automatic payment
            // methods.** Apple Pay and Google Pay are card payment methods, so
            // the element loses nothing by it, and the frontend has pinned
            // Link, PayPal, Klarna and Amazon Pay to never on its side anyway.
            //
            // Set explicitly for the reason `adaptive_pricing` is set
            // explicitly six lines into StripeCheckout: so it cannot be
            // switched on from a Dashboard nobody is watching. It also makes
            // ADR 0003's `return_url` paragraph literally true rather than
            // approximately true, since with card alone the only redirect in
            // play is 3D Secure returning to where it started.
            //
            // **The frontend states this same list, and the two are one
            // setting split across a network.** Noted 29 August 2026, after
            // the pair disagreed on a real device: its Elements group omitted
            // `paymentMethodTypes`, which *means* automatic payment methods,
            // and Stripe refused every confirmation against an intent that
            // named types explicitly: "Payment details were collected through
            // Stripe Elements using automatic payment methods and cannot be
            // confirmed through the API configured with payment_method_types
            // or allowed_payment_method_types." The sheet opened, the customer
            // authorised, and the confirm returned 400.
            //
            // So this line cannot move alone. Changing it to
            // `automatic_payment_methods` means deleting `paymentMethodTypes`
            // from the frontend's `ExpressCheckout.tsx` in the same breath,
            // and vice versa. Nothing on this side can detect the disagreement:
            // the intent is created successfully either way, and it fails one
            // round trip later in somebody's browser.
            'payment_method_types' => ['card'],

            // **No receipt_email, deliberately.** OrderPaid is the receipt.
            // Setting this would have Stripe send a second one, and two
            // receipts for one payment is a support conversation.
        ]);

        // **Written back before the secret goes out**, so a payment Stripe
        // accepts is reconcilable even if no notification ever arrives. By the
        // time one is lost there is nothing left to write this from. Easier
        // here than on the hosted road rather than harder: a PaymentIntent has
        // its id at creation, with no Session in between.
        //
        // A retry overwrites it with the newest intent, which is intended: an
        // older intent that somehow succeeded is found through the
        // notification's metadata instead, and both paths reach MarkOrderPaid.
        $order->forceFill(['stripe_payment_intent_id' => $intent->id])->save();

        return PaymentInstruction::clientSecret((string) $intent->client_secret);
    }
}
