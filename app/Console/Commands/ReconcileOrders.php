<?php

namespace App\Console\Commands;

use App\Actions\RecordStripePayment;
use App\Enums\Recording;
use App\Models\Order;
use App\Payments\StripePayment;
use Illuminate\Console\Command;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient;

/**
 * Asks Stripe about every order that has been waiting too long to be paid for.
 *
 * The webhook is the fast way to learn a payment succeeded and it is not a
 * reliable one. A notification lost in transit, or arriving mid-deploy, would
 * otherwise leave somebody who has been charged holding a pending order until a
 * person noticed, and out of hours that is however long it takes somebody to
 * look. So the notification makes it quick and this makes it certain.
 *
 * **Driven by our own table, never by listing or searching Stripe.** It is
 * bounded by our own pending orders, which is a number in the low tens, and an
 * order with no intent id had no payment started against it so it is correctly
 * invisible here. Asking Stripe what it holds would make the cost grow with
 * Stripe's history instead of with what is actually outstanding.
 *
 * **Nothing here cancels anything.** A refused or abandoned payment leaves the
 * order pending, per the no-expiry decision: the customer comes back and tries
 * another card against the same order.
 */
class ReconcileOrders extends Command
{
    /**
     * How long a payment is given to arrive by itself before this asks.
     *
     * The notification is the fast road and this is the certain one, so asking
     * about a checkout still in progress races a webhook already in flight for
     * no benefit. Both roads end at MarkOrderPaid, and the only thing bought by
     * asking sooner is one API call per order per tick for every customer
     * currently looking at a card field.
     */
    private const SETTLING_WINDOW_MINUTES = 15;

    protected $signature = 'orders:reconcile';

    protected $description = 'Ask Stripe about any order still waiting on a payment';

    public function handle(StripeClient $stripe, RecordStripePayment $record): int
    {
        $waiting = Order::query()
            ->pending()
            ->whereNotNull('stripe_payment_intent_id')
            ->where('created_at', '<=', now()->subMinutes(self::SETTLING_WINDOW_MINUTES))
            ->get();

        if ($waiting->isEmpty()) {
            $this->components->info('No order is waiting on Stripe.');

            return self::SUCCESS;
        }

        foreach ($waiting as $order) {
            $this->components->twoColumnDetail(
                'Order '.$order->id,
                $this->settle($stripe, $record, $order),
            );
        }

        return self::SUCCESS;
    }

    /**
     * What Stripe said about one order, in words for whoever is watching.
     *
     * **One unreachable payment must not cost every other customer their
     * order.** This runs unattended against a third party over a network, so a
     * timeout, a rate limit, or an id belonging to an account this environment
     * no longer holds keys for are all ordinary. Letting one end the run means
     * the orders after it are swept only if the next tick finds Stripe
     * healthier.
     */
    private function settle(StripeClient $stripe, RecordStripePayment $record, Order $order): string
    {
        try {
            $intent = $stripe->paymentIntents->retrieve($order->stripe_payment_intent_id);
        } catch (ApiErrorException $e) {
            // Logged rather than swallowed. One of these is a blip and every
            // one of them every tick is an integration that has stopped
            // working, and the two look identical from the console.
            $this->error('Stripe could not be asked about order '.$order->id.': '.$e->getMessage());

            return 'Stripe could not be asked';
        }

        $payment = StripePayment::from($intent);

        // The one settlement path, shared with the webhook. It checks the
        // amount and the currency, and MarkOrderPaid underneath it locks the
        // row and refuses a second transition, so sweeping twice is free.
        $recording = $record($payment);

        // **A `match` with no default arm, and that is the point of it.**
        // ~~`$record($payment) ? ... : 'Still '.$payment->status.' at
        // Stripe'`.~~ **Replaced 4 September 2026.** Five outcomes reached the
        // second branch and it described one of them, so a mismatch this
        // application refused was printed as `Still succeeded at Stripe`:
        // Stripe's status on a refused settlement genuinely is `succeeded`.
        // A seventh outcome added to `Recording` now stops this command
        // running rather than inheriting a sentence written about something
        // else.
        $said = match ($recording) {
            Recording::Settled => 'Paid, and settled by this sweep',
            Recording::AlreadySettled => 'Already settled, and this sweep found the work done',
            Recording::NotSucceededYet => 'Still '.$payment->status.' at Stripe',
            Recording::NotOurs => 'Not an order this environment holds',
            Recording::AmountMismatch => 'Refused, what Stripe collected does not match this order',
            Recording::BuyerUnknown => 'Refused, Stripe named nobody to settle it for',
            Recording::PaidTwice => 'Refused, this order was already paid by another payment. Refund this one by hand in Stripe',
        };

        // **Loud for a fault, the way an unreachable Stripe already is above.**
        // Somebody has been charged and has not been served, and the column
        // this returns into is a status line people skim. Which outcomes are
        // faults is `Recording`'s to say, so nothing here enumerates them a
        // second time and the console cannot come to disagree with the action.
        if ($recording->fault()) {
            // **The reason is not repeated here.** It is on the detail line
            // this method returns, directly beneath, and saying it twice made
            // the two lines one string to anything asserting on either.
            $this->error('Order '.$order->id.' was not settled.');
        }

        return $said;
    }
}
