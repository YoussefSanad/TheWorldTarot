<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\Recording;
use App\Enums\Settlement;
use App\Models\Customer;
use App\Models\Order;
use App\Payments\BuyerIdentity;
use App\Payments\PaidBy;
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
     * @return Recording Which of the seven things happened.
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

        // ~~Recorded before the transition: `charged_amount` and the intent were
        // written onto the order here unconditionally.~~ **Struck 23 October
        // 2026.** That write let a second payment overwrite the first one's
        // intent and amount on an order already paid. `MarkOrderPaid` writes
        // both in the save that settles the order and in no other.

        // **Whose order this is, if it did not already know.** An order paid
        // for through Stripe carries no identity of its own, because both roads
        // collect the email after the order exists. Everything downstream of
        // the transition addresses a customer, so this has to be resolved
        // before the transition and written inside it (decided 23 October
        // 2026, built 24 October 2026). ~~It has to be settled before it and
        // not inside it.~~
        $customerId = $this->identify($order, $payment);

        if ($customerId === null) {
            // **The one narrow write left, and only while the order is
            // pending.** The sweep finds an order through the intent on its
            // column, so without this the customer who paid is never served.
            // The pending guard is what stops it overwriting a settling
            // intent. Step 14 moves it onto the attempt and step 19 removes
            // it.
            Order::whereKey($order->id)
                ->where('status', OrderStatus::Pending)
                ->update(['stripe_payment_intent_id' => $payment->id]);

            return Recording::BuyerUnknown;
        }

        // **The one transition.** It locks the row, moves only from pending,
        // grants access inside the transaction and queues mail after commit, so
        // a redelivery finds the work done and does nothing.
        //
        // **~~`MarkOrderPaid` keeps its bool and this is where it is read.~~
        // Struck 23 October 2026.** It answers a `Settlement`, which says which
        // of four things happened, so nothing here has to infer one from a
        // `false`. `Gone` is `NotOurs` because the row vanished between the
        // read above and the lock, which is the same answer as never finding it.
        $settlement = ($this->markPaid)($order, new PaidBy($payment->id, $payment->amount, $customerId));

        if ($settlement === Settlement::PaidTwice) {
            // **Somebody has been charged twice, and nothing here can refund
            // it.** The first payment stays on the order untouched. A person
            // has to refund the second in Stripe, so this is loud.
            //
            // **Both buyers are named (step 5a, decided 23 October 2026, built 24 October 2026)**, because
            // the refund goes to the duplicate's payer and that may not be the
            // owner. One read, so the intent and the owner come from the same
            // moment.
            $settled = $order->fresh();

            Log::error('A second Stripe payment succeeded for an order that was already paid. Refund it by hand in Stripe.', [
                'order_id' => $order->id,
                'settling_intent' => $settled?->stripe_payment_intent_id,
                'duplicate_intent' => $payment->id,
                'owner_customer_id' => $settled?->customer_id,
                'duplicate_buyer_customer_id' => $customerId,
            ]);
        }

        return match ($settlement) {
            Settlement::Settled => Recording::Settled,
            Settlement::AlreadyPaid => Recording::AlreadySettled,
            Settlement::PaidTwice => Recording::PaidTwice,
            Settlement::Gone => Recording::NotOurs,
        };
    }

    /**
     * Attaching the buyer Stripe collected to the order they paid for.
     *
     * **An identity the order already carries is never overwritten.** Somebody
     * signed in owns their order from the moment they placed it, and an
     * address typed into a form is the address the account was made from.
     * Stripe's copy is what fills a gap, not what wins an argument.
     * `MarkOrderPaid` writes the owner only where `customer_id` is null.
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
     * **Writes nothing to the order (step 5a, decided 23 October 2026, built 24 October 2026).** ~~It set
     * `customer_id` while the order was pending.~~ That write ran outside the
     * row lock, so the first buyer to resolve owned an order that the first
     * intent to take the lock settled. The id is handed to `MarkOrderPaid`
     * through `PaidBy` instead, and filled inside its lock.
     *
     * @return int|null The customer who paid, or null when nobody can be named.
     */
    private function identify(Order $order, StripePayment $payment): ?int
    {
        if ($order->customer_id !== null) {
            return $order->customer_id;
        }

        $intentId = $payment->id;

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

            return null;
        }

        // **A `Customer` created here may go unused** when the order turns
        // out to have an owner already, or is already paid. That is harmless:
        // the row is keyed by email, so the next payment from this buyer
        // finds it with `firstOrCreate`, and a customer with no orders costs
        // nothing.
        return Customer::firstOrCreate(
            ['email' => $buyer->email],
            ['name' => $buyer->name, 'password' => null],
        )->getKey();
    }
}
