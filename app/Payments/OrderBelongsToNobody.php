<?php

namespace App\Payments;

use App\Models\Order;
use RuntimeException;

/**
 * An order that reached settlement without a customer.
 *
 * **A fault in the caller, not a state to handle.** An order may be placed
 * holding no identity, because Stripe's hosted page collects the buyer's email
 * after the order exists, and it stays that way for as long as the checkout is
 * open. What it may not do is become paid that way: a pass is granted to a
 * customer, a receipt is addressed to one, and a claim link invites one to set
 * a password. `RecordStripePayment` attaches the buyer before the transition
 * for exactly that reason, and answers `Recording::BuyerUnknown` rather than
 * settling if it cannot.
 *
 * Reaching `MarkOrderPaid` regardless means a road into settlement was added
 * that skipped that step, which is worth an exception rather than a quiet
 * refusal.
 */
class OrderBelongsToNobody extends RuntimeException
{
    public static function soItCannotBeSettled(Order $order): self
    {
        return new self(sprintf(
            'Order %d belongs to nobody, so there is nobody to grant it to or write to. It cannot be settled until Stripe says who paid.',
            $order->id,
        ));
    }
}
