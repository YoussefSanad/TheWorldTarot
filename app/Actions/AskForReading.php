<?php

namespace App\Actions;

use App\Mail\ReadingToWrite;
use App\Models\OrderLine;
use App\Models\Reading;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * The one moment a reading starts existing, and the telling that follows it.
 *
 * **This lived in `MarkOrderPaid::tell()` until 2 September 2026, private to
 * payment, and gifting proved it is not payment's.** A gift bought in September
 * and redeemed in December pulls "the money arrived" and "somebody asked" three
 * months apart, so the asking needs a home of its own.
 * `docs/adr/0004-a-reading-is-a-row-of-its-own.md` decides it.
 *
 * Two moments reach here and they are the same moment in different clothes:
 * settlement, for a line nobody gifted, and redemption, for a line somebody
 * did. Both arrive at this action for the reason both hand-settlement and the
 * webhook arrive at `MarkOrderPaid`, so what happens when somebody asks cannot
 * come to differ between them.
 *
 * ## One mail per asking, and this is the trap
 *
 * **Not one per reading.** `ReadingToWrite`'s own reason survives the move and
 * would otherwise be quietly lost: one message covers everything opened in the
 * same instant, so Jennifer gets a single thing to work from rather than three
 * that arrive together and have to be reassembled. A three-reading order still
 * sends one. A redemption sends its own, because it happened on its own day,
 * and that is the same rule rather than an exception to it.
 *
 * ## Where the rows are written, and where the mail is queued
 *
 * The rows go in wherever the caller's transaction is, because a reading is
 * part of what settling an order does and half of it landing is worse than
 * neither. **The mail waits for the commit**, for the reason `MarkOrderPaid`
 * gives at length: a message sent inside a transaction that then rolls back has
 * still been sent, and no amount of rolling back unsends it. `DB::afterCommit`
 * runs the callback immediately when nothing is open, so a caller with no
 * transaction of its own is not a special case here.
 */
class AskForReading
{
    /**
     * Somebody asked, about these lines, in this instant.
     *
     * **The lines are given rather than derived**, so this action never has to
     * answer "which of these is waiting for a person". `MarkOrderPaid` already
     * answers that once and branches on it, and a second copy of that question
     * living here is exactly the pair its own docblock warns about.
     *
     * **The querent is an address and a name, not a `Customer`.** A gift's
     * querent may have no customer row at all and deliberately never gets one,
     * so where a reading goes is captured onto the reading the way a line
     * captures `product_key` rather than joining for it.
     *
     * @param  Collection<int, OrderLine>  $lines  What was asked about. Never none: the caller reaches here because somebody is waiting
     * @return Collection<int, Reading>
     */
    public function __invoke(Collection $lines, string $querentEmail, ?string $querentName = null): Collection
    {
        // One instant for the whole asking, rather than `now()` per row. The
        // delivery window is counted from this, and two readings bought
        // together are not due at different times.
        $askedAt = now();

        $readings = $lines->map(fn (OrderLine $line): Reading => Reading::create([
            'order_line_id' => $line->getKey(),
            'question' => $line->question,
            'querent_email' => $querentEmail,
            'querent_name' => $querentName,
            'asked_at' => $askedAt,
        ]));

        // To orders@ since 30 August 2026, its own key rather than the one the
        // dispute mail reads. It still leaves from fulfillment@: what changed is
        // where it lands.
        $order = $lines->first()->loadMissing('order')->order;

        DB::afterCommit(function () use ($order): void {
            Mail::to(config('mail.notify.orders'))->send(new ReadingToWrite($order));
        });

        return $readings;
    }
}
