<?php

namespace App\Jobs;

use App\Actions\RecordStripePayment;
use App\Payments\StripePayment;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivering what a customer paid for, away from the request that told us.
 *
 * **The webhook answers fast and this does the work.** Stripe retries anything
 * that is not a 2xx, indefinitely, so an exception thrown while fulfilling an
 * order would become an endless redelivery of a payment that already succeeded.
 * Answering first and working afterwards is what keeps a slow or broken
 * fulfillment from turning into an outage.
 *
 * ## Running twice is expected
 *
 * Managed queues deliver at least once, and Stripe redelivers events as a
 * matter of routine, so this runs more than once for one payment and that is
 * ordinary. Nothing here guards against it, because the guard belongs where the
 * knowledge is: `MarkOrderPaid` locks the row and transitions only from
 * pending, so a second run grants nothing and sends nothing.
 *
 * ## When every attempt is gone
 *
 * The money is not lost and neither is the record. `stripe_payment_intent_id`
 * was written on the order when the payment started, so `orders:reconcile`
 * finds it, asks Stripe, and settles it. This failing means somebody is served
 * late rather than never.
 */
class SettleStripePayment implements ShouldQueue
{
    use Queueable;

    /** Unlimited, because `retryUntil` is what bounds this. */
    public int $tries = 0;

    public int $timeout = 60;

    public function __construct(public readonly StripePayment $payment) {}

    public function retryUntil(): DateTimeInterface
    {
        // Somebody has paid, so this is worth more patience than a newsletter
        // signup gets. Six hours survives a database or mail outage spanning a
        // working day, and surfaces a genuinely dead integration the same day.
        return CarbonImmutable::now()->addHours(6);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        // Fifteen minutes is a ceiling rather than a taste. Laravel Cloud's
        // managed queues cannot express a longer delay, per CLAUDE.md
        // section 2, so the schedule is built to stay inside the limit.
        return [30, 120, 900];
    }

    public function handle(RecordStripePayment $record): void
    {
        $record($this->payment);
    }

    public function failed(?Throwable $exception): void
    {
        // **Loud, because somebody has been charged.** The sweep is the
        // recovery path and it needs nobody, but a payment that got this far
        // means something is wrong that a person should see.
        Log::error('A Stripe payment could not be recorded against its order.', [
            'payment_intent' => $this->payment->id,
            'order_id' => $this->payment->orderId,
            'reason' => $exception?->getMessage(),
        ]);
    }
}
