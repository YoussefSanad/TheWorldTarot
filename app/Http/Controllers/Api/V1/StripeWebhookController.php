<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\SettleStripePayment;
use App\Mail\PaymentDisputed;
use App\Models\Order;
use App\Payments\MissingStripeSigningSecret;
use App\Payments\StripePayment;
use App\Payments\StripeSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Dispute;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\PaymentIntent;

/**
 * Stripe telling us what happened to a payment.
 *
 * **The signature is verified against the raw request body before anything is
 * read.** Hard rule 6, and the ordering is the rule: a signature covers exact
 * bytes, so parsing the JSON first and re-encoding it produces different bytes
 * and a refusal that looks like a forgery. Nothing above this line touches the
 * body but this line.
 *
 * **Answer fast, do the work on the queue.** Stripe retries anything that is
 * not a 2xx, indefinitely. An exception thrown while fulfilling an order would
 * therefore become an endless redelivery of a payment that already succeeded,
 * which is a self-inflicted outage rather than a recovery.
 *
 * **An event this site cannot use answers 200 and logs**, never 500. An unknown
 * order is a normal thing to receive, because staging and production can both
 * be reached by traffic meant for the other, and because Stripe sends event
 * types nobody subscribed to.
 *
 * There is no `charge.refunded` here, deliberately. There are no refunds, and
 * a refund made from the Stripe dashboard is invisible to this system. That is
 * recorded in `docs/plans/milestone-5-phase-b-plan.md` rather than hidden.
 */
class StripeWebhookController extends Controller
{
    public function __invoke(Request $request, StripeSignature $signatures): JsonResponse
    {
        try {
            // `getContent()` is the body as it arrived. **Nothing may parse or
            // re-encode it first**, and no tolerance is passed, so the endpoint
            // uses stripe-php's default and replay protection cannot be widened
            // from here or from config.
            $event = $signatures->verify(
                $request->getContent(),
                (string) $request->header('Stripe-Signature'),
            );
        } catch (MissingStripeSigningSecret $missing) {
            // **Refuses everything.** Failing open on a webhook is failing open
            // on granting paid access for free. Loud in the log, and from the
            // outside indistinguishable from a forgery, which it must be.
            Log::error($missing->getMessage());

            return $this->refuse();
        } catch (SignatureVerificationException|UnexpectedValueException $refused) {
            // A forgery, a body altered after signing, a signature too old to
            // still be honest, or the wrong endpoint's secret. All of them are
            // the same answer, because telling them apart tells a caller
            // something about us.
            Log::warning('A Stripe webhook was refused.', ['reason' => $refused->getMessage()]);

            return $this->refuse();
        }

        match ($event->type) {
            'payment_intent.succeeded' => $this->succeeded($event),
            'payment_intent.payment_failed' => $this->paymentFailed($event),
            'charge.dispute.created' => $this->disputed($event),
            default => Log::info('An unhandled Stripe event was ignored.', ['type' => $event->type]),
        };

        return response()->json(['received' => true]);
    }

    /**
     * **The only event that changes any state.**
     *
     * Queued rather than done here, so a slow fulfillment cannot turn into a
     * redelivery. `MarkOrderPaid` is what makes a redelivery harmless, and this
     * is the first caller that has ever exercised that.
     */
    private function succeeded(Event $event): void
    {
        $intent = $event->data->object;

        if (! $intent instanceof PaymentIntent) {
            Log::warning('A Stripe success carried something that is not a payment.', [
                'event' => $event->id,
            ]);

            return;
        }

        SettleStripePayment::dispatch(StripePayment::from($intent));
    }

    /**
     * A card was refused. Nothing moves, and the customer retries against the
     * order they already have.
     */
    private function paymentFailed(Event $event): void
    {
        $intent = $event->data->object;

        Log::info('A Stripe payment failed. The order stays pending.', [
            'payment_intent' => $intent instanceof PaymentIntent ? $intent->id : null,
            'order_id' => $intent instanceof PaymentIntent
                ? StripePayment::from($intent)->orderId
                : null,
        ]);
    }

    /**
     * Somebody has gone to their bank.
     *
     * **Nothing here changes any state**, and that is the design rather than an
     * omission. There are no refunds and nothing is revoked. This exists so a
     * chargeback is something Jennifer learns about rather than discovers, and
     * because responding to one has a deadline she cannot meet if nobody tells
     * her.
     */
    private function disputed(Event $event): void
    {
        $dispute = $event->data->object;

        if (! $dispute instanceof Dispute) {
            Log::warning('A Stripe dispute carried something that is not a dispute.', [
                'event' => $event->id,
            ]);

            return;
        }

        $order = Order::where('stripe_payment_intent_id', $dispute->payment_intent)->first();

        Log::warning('A customer has disputed a payment.', [
            'dispute' => $dispute->id,
            'payment_intent' => $dispute->payment_intent,
            'order_id' => $order?->id,
            'amount' => $dispute->amount,
            'reason' => $dispute->reason,
        ]);

        Mail::to(config('mail.notify.fulfillment'))->send(new PaymentDisputed(
            disputeId: (string) $dispute->id,
            amount: (int) $dispute->amount,
            currency: (string) $dispute->currency,
            reason: (string) ($dispute->reason ?: 'not given'),
            order: $order,
        ));
    }

    /**
     * The one refusal, for every reason there is to refuse.
     *
     * 400 rather than 200, so Stripe retries: the usual cause is a secret this
     * environment has not been given yet, and a redelivery after somebody sets
     * it is exactly the recovery wanted. A forgery being retried costs nothing.
     */
    private function refuse(): JsonResponse
    {
        return response()->json(['received' => false], 400);
    }
}
