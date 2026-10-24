<?php

namespace App\Payments;

/**
 * The payment that is settling an order, and the customer who made it.
 *
 * **`$customerId` is a required int, decided 23 October 2026 (step 5a, option
 * A).** ~~A `PaidBy` held only the intent and the amount, and the owner was
 * written earlier by `RecordStripePayment`.~~ That earlier write let the first
 * buyer to resolve own an order that the first intent to take the row lock
 * settled, so two people paying at once could leave the order owned by the one
 * whose payment was then logged as a duplicate. The id is carried here and
 * `MarkOrderPaid` fills `customer_id` from it inside its lock, only when the
 * order has no owner. It is never used to change an existing owner.
 */
final class PaidBy
{
    public function __construct(
        public readonly string $intentId,
        public readonly int $amount,
        public readonly int $customerId,
    ) {}
}
