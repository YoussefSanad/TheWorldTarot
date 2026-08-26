<?php

namespace App\Payments;

use App\Models\Order;
use RuntimeException;

/**
 * An order a payment must not be started for.
 *
 * Two cases, and both are faults rather than things a customer did. An order
 * whose stored total disagrees with its own lines has no honest amount to
 * charge, and charging either number is wrong. An order that is not pending has
 * nothing to collect, and the endpoint answers that without getting this far.
 */
class OrderCannotBeCharged extends RuntimeException
{
    public static function totalDisagreesWithItsLines(Order $order): self
    {
        return new self(sprintf(
            'Order %d stores a total of %d but its lines come to %d. Nothing may be charged until that is explained.',
            $order->id,
            $order->total_amount,
            $order->totalOfLines(),
        ));
    }

    public static function itIsNotPending(Order $order): self
    {
        return new self(sprintf(
            'Order %d is %s, so there is nothing to collect for it.',
            $order->id,
            $order->status->value,
        ));
    }
}
