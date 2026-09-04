<?php

namespace App\Enums;

/**
 * What happened when a payment Stripe reported was recorded against its order.
 *
 * **A boolean could not carry this and the evidence went to the logger.** Six
 * things can happen in `RecordStripePayment` and five of them answered `false`,
 * so `orders:reconcile` rendered a settlement it had refused as
 * `Still succeeded at Stripe`: the operator's console said a payment was in
 * flight while `Log::error` said it had been turned away. Nobody is watching
 * the log. The tests followed the evidence there too, spying on the `Log`
 * facade because the return value could not tell "left pending because Stripe
 * named nobody" from any other `false`.
 *
 * Same argument as `ClaimOutcome` and `PaymentInstruction`, and the same shape:
 * a scalar carries one of the answers plus folklore about what the others mean.
 *
 * **The cases are not a flat list, they are three kinds**, and `fault()` is
 * where that is written down rather than re-derived by each caller:
 *
 * - **Changed.** `Settled`, and only that
 * - **A no-op.** `AlreadySettled`, `NotSucceededYet`, `NotOurs`. Nothing to do
 *   and nothing wrong
 * - **A fault.** `AmountMismatch`, `BuyerUnknown`. Somebody has been charged
 *   and this application refused to settle it, which a person has to see
 *
 * **Not backed**, because nothing stores or transmits one. It is an answer
 * passed between two objects in one call, and a backing value would be a
 * second name for each case that nothing keeps honest.
 */
enum Recording
{
    /** This call is the one that marked the order paid. */
    case Settled;

    /**
     * It was already paid, which is a success rather than a problem.
     *
     * Ordinary rather than rare from the sweep's point of view: managed queues
     * deliver at least once and Stripe redelivers events as a matter of
     * routine, so a webhook and a sweep racing over one payment is the shape
     * `MarkOrderPaid`'s row lock exists for.
     */
    case AlreadySettled;

    /**
     * The card was refused, or the payment is still in flight.
     *
     * The order stays pending and the customer retries against it, per the
     * no-expiry decision.
     */
    case NotSucceededYet;

    /**
     * The payment named an order this environment does not have.
     *
     * **Normal, not exceptional**, and unreachable from `orders:reconcile`,
     * which sweeps orders read out of our own table. It is the webhook's case:
     * staging and production can both be reached by traffic meant for the
     * other.
     */
    case NotOurs;

    /**
     * Stripe collected an amount or a currency the order does not agree with.
     *
     * **Refused, never settled.** Stripe Tax is off and prices are tax
     * inclusive, so what was collected always equals what the order says, and
     * granting access for a number nobody can explain is the worst available
     * answer.
     */
    case AmountMismatch;

    /**
     * The payment succeeded and there is nobody to settle it for.
     *
     * Neither a Checkout Session nor the payment's own charge named a buyer.
     * Everything settlement does is addressed to somebody, so the order stays
     * pending holding the intent that paid for it and a later sweep settles it
     * once one of the two can be read.
     */
    case BuyerUnknown;

    /**
     * Whether somebody has been charged and this refused to settle it.
     *
     * **The one place the three kinds are written down.** `ReconcileOrders`
     * asks this rather than listing the cases a second time, so the console
     * cannot come to disagree with what the action actually did.
     *
     * **A `match` with no default arm, deliberately.** A seventh outcome added
     * above stops this method running rather than quietly inheriting "not a
     * fault", which is exactly how a refused settlement came to be reported as
     * a healthy one.
     */
    public function fault(): bool
    {
        return match ($this) {
            self::AmountMismatch, self::BuyerUnknown => true,
            self::Settled, self::AlreadySettled, self::NotSucceededYet, self::NotOurs => false,
        };
    }
}
