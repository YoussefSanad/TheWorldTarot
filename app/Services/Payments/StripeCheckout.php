<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\ProductKey;
use App\Models\Order;
use App\Payments\OrderCannotBeCharged;
use App\Payments\PaymentInstruction;
use App\Payments\RefuseAnythingButAPendingOrder;
use App\Support\FrontendUrl;
use Stripe\StripeClient;

/**
 * Taking a card, a wallet or whatever else Stripe accepts, on Stripe's page.
 *
 * **A Checkout Session is created here and the customer is sent to it**, since
 * 29 August 2026. This class made a PaymentIntent and handed its client secret
 * to elements mounted on the reading page until then; the frontend chose one
 * button of a known size over a wallet sheet whose height it cannot lay a panel
 * out around. See docs/adr/0002-the-redirect-case-comes-back.md.
 *
 * **What did not move is the more important half.** The amount is the stored
 * order's total, built as `price_data` and never a Stripe Price. Settlement is
 * still `payment_intent.succeeded` keyed by `metadata.order_id`, now carried
 * through `payment_intent_data`, and `checkout.session.completed` is still not
 * subscribed to.
 *
 * **This is the card button, and it is no longer the only road.** `StripeWallet`
 * beside it mints a PaymentIntent for the express checkout element on the
 * frontend's own page, and the two are separate methods in
 * `config/payments.php` because they are a hosted Session and a PaymentIntent.
 * The guard they share moved out of this class and into
 * `App\Payments\RefuseAnythingButAPendingOrder` when the second one arrived.
 * See docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md.
 *
 * **Nothing here proves Stripe accepts what it sends.** A test against a
 * substituted StripeClient agrees with this class whatever this class does. The
 * authority is the captured signature vector on the webhook and the walk
 * against a deployed environment. See .claude/external-services.md.
 */
class StripeCheckout implements PaymentGateway
{
    public function __construct(
        private readonly StripeClient $stripe,
        private readonly RefuseAnythingButAPendingOrder $refuseAnythingButAPendingOrder,
    ) {}

    public function begin(Order $order, ?ProductKey $returnTo = null): PaymentInstruction
    {
        ($this->refuseAnythingButAPendingOrder)($order);

        $session = $this->stripe->checkout->sessions->create([
            'mode' => 'payment',

            // **One line, priced at the order's stored total.** The lines are
            // what the total was arrived at from and the total is the authority
            // on what is charged, and the guard above has just agreed the two.
            //
            // **`price_data`, never a Stripe Price.** Jennifer edits prices; a
            // price held at Stripe is a second place they can be edited and a
            // second number the payment can be made against.
            'line_items' => [[
                'quantity' => 1,
                'price_data' => [
                    'unit_amount' => $order->total_amount,
                    'currency' => strtolower($order->currency),
                    'product_data' => ['name' => $this->whatTheyAreBuying($order)],
                ],
            ]],

            // **Never let Stripe convert.** A converted amount is one the
            // customer is charged that is not the order total, and then
            // ReconcileOrders and the total-vs-lines guard are comparing
            // numbers in two currencies. Set explicitly so it cannot be
            // switched on from a Dashboard nobody is watching.
            'adaptive_pricing' => ['enabled' => false],

            // The reading is written in English, so the hosted page is too
            // rather than picking a language from the buyer's browser.
            'locale' => 'en',

            // **How the webhook and the sweep find their way back to this
            // order, and it has to be here rather than on the Session.**
            // Settlement is `payment_intent.succeeded` keyed by
            // `metadata.order_id`; we do not subscribe to
            // `checkout.session.completed`, so metadata the Session keeps to
            // itself settles nothing. A string, because Stripe metadata values
            // are strings.
            'payment_intent_data' => [
                'metadata' => ['order_id' => (string) $order->id],
            ],

            'success_url' => FrontendUrl::checkoutComplete(),
            // **Built here from a configured origin, never from anything the
            // caller sent.** A caller-supplied URL is an open redirect
            // available to anybody holding a pay token. All the caller may say
            // is which reading page it came from, and it says that as an enum.
            'cancel_url' => FrontendUrl::readings($returnTo),
        ]);

        // **Written back before the instruction goes out**, so a payment Stripe
        // accepts is reconcilable even if no notification ever arrives. By the
        // time one is lost there is nothing left to write this from.
        //
        // **Conditional on the row still being pending**, because the
        // model in hand predates the Stripe call and a plain save would
        // overwrite the intent that settled the order. Plan step 19 removes
        // this write entirely.
        $written = Order::whereKey($order->id)
            ->where('status', OrderStatus::Pending)
            ->update(['stripe_payment_intent_id' => $session->payment_intent]);

        if ($written === 0) {
            // **Refused rather than skipped, the way a not-pending order is
            // refused at the door.** The webhook settled the order while we
            // were waiting on Stripe, so the intent just minted can never
            // matter and its secret must not reach a browser: handing it over
            // would invite a second charge on a paid order. Reloaded first so
            // the message names the status it really has, not the stale one.
            //
            // `fresh()` rather than `refresh()`, because the row may have been
            // deleted during the call too and `refresh()` would throw
            // ModelNotFoundException. A missing row is refused the same way,
            // on the model in hand.
            throw OrderCannotBeCharged::itIsNotPending($order->fresh() ?? $order);
        }

        $order->setAttribute('stripe_payment_intent_id', $session->payment_intent);
        $order->syncOriginalAttribute('stripe_payment_intent_id');

        // **The Session's address is never persisted.** It expires after 24
        // hours and an order never does, so it is only ever the answer to the
        // call that minted it.
        return PaymentInstruction::redirect($session->url);
    }

    /**
     * What the hosted page calls this, which is the only thing on it a customer
     * reads.
     *
     * **The one caller that guards for no line at all**, because an order with
     * nothing on it has nothing to name and `displayName()` is a line's answer.
     * Nothing places an empty order today, and a payment page that says nothing
     * would be worse than the sentence below.
     */
    private function whatTheyAreBuying(Order $order): string
    {
        $line = $order->lines->first();

        return $line?->displayName() ?? 'Your order';
    }
}
