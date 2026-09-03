<?php

namespace App\Actions;

use App\Models\Reading;

/**
 * The one moment a reading has gone.
 *
 * **This is the seam a PDF upload later hangs off, and that is the whole
 * reason it exists as an action rather than as a button.** ADR 0006 refuses the
 * upload for the gifting epic and says why: file storage, a mailable with an
 * attachment, a delivery failure path and a new meaning for
 * `Fulfillment::ManualPdf`, none of which is gifting. What it does not refuse
 * is the shape. When the upload arrives it stores the file, sends the mail and
 * then calls this, rather than replacing it and becoming a second writer for
 * `readings.delivered_at`.
 *
 * `MarkOrderFulfilled` was written because that column had two writers agreeing
 * only because both happened to write `now()`. This is the same lesson applied
 * one row down, before the second writer exists rather than after.
 *
 * **The order following is part of what delivering is**, not a separate step a
 * caller has to remember. She delivers a reading and the order becomes
 * fulfilled when everything it owes has gone, so asking is done here, once,
 * where every caller reaches it.
 */
class DeliverReading
{
    public function __construct(
        private readonly MarkOrderFulfilled $markFulfilled,
    ) {}

    /**
     * @return bool Whether this call is the one that changed anything. False
     *              means it had already gone, which is a success.
     */
    public function __invoke(Reading $reading): bool
    {
        // The first date stands, the way `MarkOrderFulfilled` and
        // `grantViewingRoomAccess` keep theirs. When a thing was delivered is a
        // fact about the past, and a second call is not news about it.
        if ($reading->isDelivered()) {
            return false;
        }

        $reading->forceFill(['delivered_at' => now()])->save();

        // **Asked, not assumed.** An order holding two readings is not finished
        // by the first one going, and one holding an unredeemed gift is not
        // finished by its readings at all. `Order` owns that question and
        // `MarkOrderFulfilled` owns the recording, so this only joins them.
        $order = $reading->orderLine->order;

        if ($order->everythingItOwesHasGone()) {
            ($this->markFulfilled)($order);
        }

        return true;
    }
}
