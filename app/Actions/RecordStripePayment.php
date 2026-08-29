<?php

namespace App\Actions;

use App\Models\Customer;
use App\Models\Order;
use App\Payments\BuyerIdentity;
use App\Payments\StripePayment;
use App\Services\Payments\BuyerFromCheckoutSession;
use Illuminate\Support\Facades\Log;

/**
 * A payment Stripe says succeeded, turned into an order that is paid.
 *
 * **Reached by two roads and written once.** A webhook is the quick way to
 * learn about a payment and not a certain one, so a sweep asks Stripe about
 * anything still pending. Both arrive here, and here calls `MarkOrderPaid`,
 * which is the one moment an order becomes paid for a person in the panel as
 * well. Fulfillment cannot come to differ between the three.
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
        private readonly BuyerFromCheckoutSession $buyers,
    ) {}

    /**
     * @return bool Whether this call is the one that changed anything. False
     *              covers a payment that did not succeed, an order nobody here
     *              has, a mismatch, and a redelivery of something already done.
     */
    public function __invoke(StripePayment $payment): bool
    {
        if (! $payment->succeeded()) {
            // The customer's card was refused, or the payment is still in
            // flight. The order stays pending and they retry against it.
            Log::info('A Stripe payment that has not succeeded was not recorded.', [
                'payment_intent' => $payment->id,
                'status' => $payment->status,
            ]);

            return false;
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

            return false;
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

            return false;
        }

        // Recorded before the transition, so an order that is marked paid is
        // never briefly paid for an amount nobody wrote down. Rewriting the
        // same values on a redelivery costs nothing and changes nothing.
        $order->forceFill([
            'charged_amount' => $payment->amount,
            'stripe_payment_intent_id' => $payment->id,
        ])->save();

        // **Whose order this is, if it did not already know.** An order placed
        // through Stripe's hosted page carries no identity, because that page
        // collects the email after the order exists. Everything downstream of
        // the transition addresses a customer, so this has to be settled
        // before it and not inside it.
        if (! $this->identify($order)) {
            return false;
        }

        // **The one transition.** It locks the row, moves only from pending,
        // grants access inside the transaction and queues mail after commit, so
        // a redelivery finds the work done and does nothing.
        return ($this->markPaid)($order);
    }

    /**
     * Attaching the buyer Stripe collected to the order they paid for.
     *
     * **An identity the order already carries is never overwritten.** Somebody
     * signed in owns their order from the moment they placed it, and an
     * address typed into a form is the address the account was made from.
     * Stripe's copy is what fills a gap, not what wins an argument.
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

        $buyer = $this->buyers->forPaymentIntent($intentId);

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
            // comes back to it, and a later attempt that can read the Session
            // settles it properly. Somebody is served late rather than never.
            Log::error('A paid order could not be attached to a buyer and was left pending.', [
                'order_id' => $order->id,
                'payment_intent' => $intentId,
            ]);

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
