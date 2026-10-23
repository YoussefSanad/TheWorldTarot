<?php

namespace App\Actions;

use App\Enums\Recording;
use App\Models\Customer;
use App\Models\Order;
use App\Payments\BuyerIdentity;
use App\Payments\StripePayment;
use App\Services\Payments\BuyerFromCheckoutSession;
use App\Services\Payments\BuyerFromLatestCharge;
use Illuminate\Support\Facades\Log;

/**
 * A payment Stripe says succeeded, turned into an order that is paid.
 *
 * **Reached by two roads and written once.** A webhook is the quick way to
 * learn about a payment and not a certain one, so a sweep asks Stripe about
 * anything still pending. Both arrive here, and here calls `MarkOrderPaid`,
 * which is the one moment an order becomes paid. ~~For a person in the panel as
 * well. Fulfillment cannot come to differ between the three.~~ **Struck 22 October 2026:
 * the panel no longer marks orders paid**, so there are two roads and not three.
 *
 * **Nothing here throws for anything Stripe can legitimately send.** An event
 * about an order this environment does not have, or one whose amount disagrees,
 * is logged and refused rather than raised: Stripe retries a non-2xx
 * indefinitely, and an exception on the way to a 200 is a self-inflicted
 * outage about a payment that already succeeded.
 */
class RecordStripePayment
{
    public function __construct(
        private readonly MarkOrderPaid $markPaid,
        private readonly BuyerFromCheckoutSession $buyerFromSession,
        private readonly BuyerFromLatestCharge $buyerFromCharge,
    ) {}

    /**
     * @return Recording Which of the six things happened.
     *
     * **~~A bool, whether this call is the one that changed anything.~~
     * Replaced 4 September 2026.** Six outcomes answered one of two words, so
     * the only place they could be told apart was `Log::error`, and
     * `orders:reconcile` rendered every `false` as `Still {status} at Stripe`.
     * On a refused mismatch that status **is** `succeeded`, so the one console
     * a person actually watches reported a settlement this action had turned
     * away as a payment quietly in flight. See `Recording`.
     */
    public function __invoke(StripePayment $payment): Recording
    {
        if (! $payment->succeeded()) {
            // The customer's card was refused, or the payment is still in
            // flight. The order stays pending and they retry against it.
            Log::info('A Stripe payment that has not succeeded was not recorded.', [
                'payment_intent' => $payment->id,
                'status' => $payment->status,
            ]);

            return Recording::NotSucceededYet;
        }

        $order = $payment->orderId === null ? null : Order::find($payment->orderId);

        if ($order === null) {
            // **Normal, not exceptional.** Staging and production can both be
            // reached by a test event, and an endpoint that errored on one
            // would ask Stripe to redeliver it forever.
            Log::info('A Stripe payment named an order this environment does not have.', [
                'payment_intent' => $payment->id,
                'order_id' => $payment->orderId,
            ]);

            return Recording::NotOurs;
        }

        if (! $payment->matches($order->total_amount, $order->currency)) {
            // **Refused and reported, never settled.** Stripe Tax is off and
            // prices are tax inclusive, so what was collected always equals
            // what the order says. A difference means something is wrong
            // upstream of here, and granting access for a number nobody can
            // explain is the worst available answer.
            Log::error('A Stripe payment did not match the order it named.', [
                'payment_intent' => $payment->id,
                'order_id' => $order->id,
                'charged' => $payment->amount,
                'charged_currency' => $payment->currency,
                'order_total' => $order->total_amount,
                'order_currency' => $order->currency,
            ]);

            // **Named rather than merely logged, and this is the line the
            // outcome type exists for.** A refusal here used to answer the
            // same `false` a card still being authorised answers with, and the
            // sweep prints what it is handed.
            return Recording::AmountMismatch;
        }

        // Recorded before the transition, so an order that is marked paid is
        // never briefly paid for an amount nobody wrote down. Rewriting the
        // same values on a redelivery costs nothing and changes nothing.
        $order->forceFill([
            'charged_amount' => $payment->amount,
            'stripe_payment_intent_id' => $payment->id,
        ])->save();

        // **Whose order this is, if it did not already know.** An order paid
        // for through Stripe carries no identity of its own, because both roads
        // collect the email after the order exists. Everything downstream of
        // the transition addresses a customer, so this has to be settled
        // before it and not inside it.
        if (! $this->identify($order)) {
            return Recording::BuyerUnknown;
        }

        // **The one transition.** It locks the row, moves only from pending,
        // grants access inside the transaction and queues mail after commit, so
        // a redelivery finds the work done and does nothing.
        //
        // **`MarkOrderPaid` keeps its bool and this is where it is read.**
        // Theirs means one thing, "this call is the one that changed
        // anything", and there are genuinely only two answers to it. What is
        // added here is which of the two, said out loud, so a redelivery is
        // reported as work already done rather than as a payment nobody has
        // finished collecting.
        //
        // **`AlreadySettled` is the honest reading of that `false` from here
        // and not from everywhere.** `MarkOrderPaid` answers it for any order
        // that is no longer pending, which includes a cancelled one and a row
        // that has gone. Neither can arrive here: nothing in this application
        // writes `OrderStatus::Cancelled`, and both roads in reach an order
        // they have just read. A caller that could cancel would have to ask
        // the transition which of the three it meant rather than widen this
        // line.
        return ($this->markPaid)($order)
            ? Recording::Settled
            : Recording::AlreadySettled;
    }

    /**
     * Attaching the buyer Stripe collected to the order they paid for.
     *
     * **An identity the order already carries is never overwritten.** Somebody
     * signed in owns their order from the moment they placed it, and an
     * address typed into a form is the address the account was made from.
     * Stripe's copy is what fills a gap, not what wins an argument.
     *
     * **Two sources, tried in that order, because there are two roads in.** The
     * hosted page collects the email on a Checkout Session. A wallet payment
     * has no Session at all, and the email it collected sits on the intent's
     * own charge. Trying the Session first costs the wallet road one empty list
     * on a job that already holds six hours of retries. See
     * docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md.
     *
     * **The fall through is `??`, so it fires whenever the Session answered
     * nobody, not only when there was no Session.** ~~Trying the Session first
     * leaves the hosted road bit for bit as it was.~~ **Corrected 29 August
     * 2026**, because that sentence was not true of the code beneath it: a
     * Session that collected no email, and a Stripe that could not be reached
     * at all, both answer null and both now reach the charge. That is wanted
     * rather than tolerated. Every one of those cases ends with a customer who
     * has been charged and an order nobody can settle, and a second read that
     * might name them is worth one API call against that. What it costs the
     * hosted road is that call, on the only path that was already failing.
     *
     * The three hosted-road cases in `SettleAnUnownedOrderTest` name a charge
     * explicitly for this reason, and a bare mock there fails loudly rather
     * than quietly answering nobody.
     *
     * `firstOrCreate` is safe against two settlements arriving together: the
     * framework catches the unique violation on `email` inside a savepoint and
     * re-reads, exactly as it does when two first orders arrive together.
     *
     * @return bool Whether this order can now be settled.
     */
    private function identify(Order $order): bool
    {
        if ($order->customer_id !== null) {
            return true;
        }

        $intentId = (string) $order->stripe_payment_intent_id;

        $buyer = $this->buyerFromSession->forPaymentIntent($intentId)
            ?? $this->buyerFromCharge->forPaymentIntent($intentId);

        if (! $buyer instanceof BuyerIdentity) {
            // **Refused rather than settled, and loud.** Everything settlement
            // does is addressed to somebody: a pass is granted to a customer,
            // a receipt is sent to an address, a claim link invites a person
            // to set a password. There is nobody here to do any of it for, and
            // marking the order paid would spend the one transition that
            // delivers all of it.
            //
            // **The money is not lost and neither is this order.** It stays
            // pending holding the intent that paid for it, so `orders:reconcile`
            // comes back to it, and a later attempt that can read one of the two
            // settles it properly. Somebody is served late rather than never.
            //
            // **It names both sources it exhausted.** This is the line that
            // fires if the wallet's `emailRequired` turns out not to reach the
            // charge, so it has to say which two places were looked in rather
            // than send the reader to one of two resolvers at random.
            //
            // **It does not claim both were read.** This branch is also where
            // an unreachable Stripe arrives, and both resolvers log their own
            // failure before answering null, so the reason is already in the
            // log above this line rather than guessed at in it.
            Log::error(
                'A paid order could not be attached to a buyer and was left pending. '
                .'Neither a Checkout Session nor the payment\'s own charge billing details could be '
                .'read, or either was read and named nobody.',
                [
                    'order_id' => $order->id,
                    'payment_intent' => $intentId,
                ],
            );

            return false;
        }

        $customer = Customer::firstOrCreate(
            ['email' => $buyer->email],
            ['name' => $buyer->name, 'password' => null],
        );

        $order->forceFill(['customer_id' => $customer->getKey()])->save();
        $order->setRelation('customer', $customer);

        return true;
    }
}
