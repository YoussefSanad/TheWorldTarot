<?php

namespace App\Console\Commands;

use App\Actions\RecordStripePayment;
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
        return $record($payment)
            ? 'Paid, and settled by this sweep'
            : 'Still '.$payment->status.' at Stripe';
    }
}
