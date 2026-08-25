<?php

namespace App\Actions;

use App\Models\Order;

/**
 * The one moment an order is delivered.
 *
 * Two things arrive here and they mean the same thing by it. An order holding
 * nothing anybody has to act on is delivered the instant the money lands, and
 * an order holding a reading is delivered when Jennifer says she has emailed
 * it. One word covers both because only one word is true of both, which is why
 * the column is "fulfilled" rather than "sent".
 *
 * **It exists because the column had two writers**, one at payment and one in
 * the panel. They agreed only because both happened to write `now()`, which is
 * agreement by hand rather than by structure. The day fulfillment means
 * anything more than a timestamp, a second writer is the one that does not
 * learn it.
 *
 * Nothing here decides whether an order should be fulfilled. MarkOrderPaid
 * decides that for the payment path, and the panel action's visibility decides
 * it for Jennifer, because those two are answering genuinely different
 * questions. This owns only what being delivered is recorded as.
 */
class MarkOrderFulfilled
{
    /**
     * @return bool Whether this call is the one that changed anything. False
     *              means it was already fulfilled, which is a success.
     */
    public function __invoke(Order $order): bool
    {
        // The first date stands, the way grantViewingRoomAccess keeps the first
        // date somebody got in. When a thing was delivered is a fact about the
        // past, and a second call is not news about it.
        if ($order->isFulfilled()) {
            return false;
        }

        $order->forceFill(['fulfilled_at' => now()])->save();

        return true;
    }
}
