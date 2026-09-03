<?php

namespace App\Actions;

use App\Mail\ReadingOnItsWay;
use App\Mail\ReadingToWrite;
use App\Models\Gift;
use App\Models\OrderLine;
use App\Models\Reading;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
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
     * @param  SupportCollection<int, OrderLine>  $lines  What was asked about. Never none: the caller reaches here because somebody is waiting
     * @return SupportCollection<int, Reading>
     */
    public function __invoke(SupportCollection $lines, string $querentEmail, ?string $querentName = null): SupportCollection
    {
        // Settlement's road. The question is the one the buyer typed on each
        // line at checkout, which is why nothing is passed in here.
        return $this->ask($lines, null, null, $querentEmail, $querentName);
    }

    /**
     * Somebody spent a present, and this is what they asked with it.
     *
     * **Redemption's road, and it is one line by construction.** The line is
     * read off the gift rather than handed in, because a gift is exactly one
     * line bought once, which `gifts.order_line_id` being unique is what says.
     * Taking a collection here would be an interface that can be called with
     * the wrong line and has no way to notice.
     *
     * **The question comes from the request and not from the line.**
     * `order_lines.question` is null on a gift line: the person who asks has
     * not seen the present at the point the buyer types anything, and asking is
     * what redemption is for. `CONTEXT.md` keeps those two apart as the
     * question of record and what the buyer typed.
     *
     * B7 (#73). `RedeemGift` is the only caller and holds the row lock this
     * runs inside.
     */
    public function forGift(Gift $gift, string $question, string $querentEmail, ?string $querentName = null): Reading
    {
        // **An Eloquent collection and not `collect()`.** `ReadingToWrite`
        // eager loads off what it is handed, which only an Eloquent collection
        // can do, and settlement reaches this action holding one already. A
        // support collection here is a fatal error in the mail rather than at
        // the call, which is why the mailable's own hint was corrected with
        // this unit.
        $reading = $this->ask(
            new Collection([$gift->orderLine]),
            $gift,
            $question,
            $querentEmail,
            $querentName,
        )->sole();

        // **Redemption's road sends a second message, and settlement's does
        // not.** B8 (#74). The querent of a gift has no account, no order and
        // no lookup, so the delivery promise the confirmation makes dies with
        // her tab, and `ReadingOnItsWay` is the durable record that a clock
        // started. A buyer already has the receipt and reaches this action
        // through `__invoke`, which is why the branch is a road rather than an
        // `if` inside the writer both roads share.
        //
        // Queued after the commit like the one `ask()` arranges, and for the
        // same reason: this runs inside `RedeemGift`'s transaction, and a
        // message sent from inside one that then rolls back promises a reading
        // to somebody whose gift is still unspent.
        DB::afterCommit(function () use ($reading): void {
            Mail::to($reading->querent_email)->send(new ReadingOnItsWay($reading));
        });

        return $reading;
    }

    /**
     * The rows, and the message that follows them.
     *
     * **One writer for both roads, which is what `Reading`'s own docblock
     * claims.** Settlement and redemption are the same moment in different
     * clothes, so what happens when somebody asks cannot come to differ between
     * them, and a second `Reading::create` elsewhere is how it would.
     *
     * @param  SupportCollection<int, OrderLine>  $lines
     * @param  ?Gift  $gift  The present this was asked with, on the redemption road and never on the other
     * @param  ?string  $question  Given on the redemption road; null means the question each line already carries
     * @return SupportCollection<int, Reading>
     */
    private function ask(
        SupportCollection $lines,
        ?Gift $gift,
        ?string $question,
        string $querentEmail,
        ?string $querentName,
    ): SupportCollection {
        // One instant for the whole asking, rather than `now()` per row. The
        // delivery window is counted from this, and two readings bought
        // together are not due at different times.
        $askedAt = now();

        $readings = $lines->map(fn (OrderLine $line): Reading => Reading::create([
            'order_line_id' => $line->getKey(),
            'gift_id' => $gift?->getKey(),
            'question' => $question ?? $line->question,
            'querent_email' => $querentEmail,
            'querent_name' => $querentName,
            'asked_at' => $askedAt,
        ]));

        // To orders@ since 30 August 2026, its own key rather than the one the
        // dispute mail reads. It still leaves from fulfillment@: what changed is
        // where it lands.
        $order = $lines->first()->loadMissing('order')->order;

        DB::afterCommit(function () use ($order, $readings): void {
            // **The readings go with it, not the order.** A basket may hold a
            // present and a self purchase together and only one of them is
            // work, so what she is told about is what was actually asked. B6
            // (#72) is where that stopped being the same list.
            Mail::to(config('mail.notify.orders'))->send(new ReadingToWrite($order, $readings));
        });

        return $readings;
    }
}
