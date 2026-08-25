<?php

namespace App\Payments;

use Stripe\PaymentIntent;
use Stripe\StripeObject;

/**
 * One payment at Stripe, as this application needs to read it.
 *
 * **One value from one shape, and two callers reach it by different roads.**
 * The webhook is handed a PaymentIntent inside a verified event, and the sweep
 * retrieves one by id. They are the same object, so they become the same value
 * here rather than two nearly identical readings of Stripe's payload.
 *
 * Plain scalars, so a queued job can carry it without dragging a Stripe client
 * through serialization.
 */
class StripePayment
{
    public function __construct(
        public readonly string $id,
        public readonly ?int $orderId,
        public readonly int $amount,
        public readonly string $currency,
        public readonly string $status,
    ) {}

    public static function from(PaymentIntent $intent): self
    {
        $metadata = $intent->metadata instanceof StripeObject
            ? $intent->metadata->toArray()
            : (array) $intent->metadata;

        $orderId = $metadata['order_id'] ?? null;

        return new self(
            id: (string) $intent->id,

            // **Absent or unreadable is null, never a guess.** An event about an
            // order this environment does not have is a normal thing to receive,
            // since staging traffic reaching production is a live possibility,
            // and it is answered rather than errored.
            orderId: is_numeric($orderId) ? (int) $orderId : null,

            // What was actually collected, which is the number that has to
            // agree with a bank statement. `amount` is what was asked for.
            amount: (int) ($intent->amount_received ?: $intent->amount),

            currency: (string) $intent->currency,
            status: (string) $intent->status,
        );
    }

    public function succeeded(): bool
    {
        return $this->status === PaymentIntent::STATUS_SUCCEEDED;
    }

    /**
     * Whether this is a payment for what the order actually says it costs.
     *
     * Stripe Tax is off and prices are tax inclusive, so these always agree and
     * a difference is a fault to detect rather than a case to handle.
     */
    public function matches(int $amount, string $currency): bool
    {
        return $this->amount === $amount
            && strtolower($this->currency) === strtolower($currency);
    }
}
