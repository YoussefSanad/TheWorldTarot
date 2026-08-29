<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\ProductKey;
use App\Models\Order;
use App\Payments\OrderCannotBeCharged;
use App\Payments\PaymentInstruction;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\Log;
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
 * subscribed to. The pending-order guard below is untouched.
 *
 * **Nothing here proves Stripe accepts what it sends.** A test against a
 * substituted StripeClient agrees with this class whatever this class does. The
 * authority is the captured signature vector on the webhook and the walk
 * against a deployed environment. See .claude/external-services.md.
 */
class StripeCheckout implements PaymentGateway
{
    public function __construct(private readonly StripeClient $stripe) {}

    public function begin(Order $order, ?ProductKey $returnTo = null): PaymentInstruction
    {
        $this->refuseAnythingButAPendingOrder($order);

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
        $order->forceFill(['stripe_payment_intent_id' => $session->payment_intent])->save();

        // **The Session's address is never persisted.** It expires after 24
        // hours and an order never does, so it is only ever the answer to the
        // call that minted it.
        return PaymentInstruction::redirect($session->url);
    }

    /**
     * What the hosted page calls this, which is the only thing on it a customer
     * reads.
     */
    private function whatTheyAreBuying(Order $order): string
    {
        $line = $order->lines->first();

        return $line?->product?->name ?? $line?->product_key?->defaultName() ?? 'Your order';
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
