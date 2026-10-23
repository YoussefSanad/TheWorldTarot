<?php

namespace App\Actions;

use App\Enums\Fulfillment;
use App\Enums\OrderStatus;
use App\Enums\Settlement;
use App\Mail\GiftToRedeem;
use App\Mail\OrderPaid;
use App\Mail\SetPasswordLink;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Payments\OrderBelongsToNobody;
use App\Payments\PaidBy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * The one moment an order becomes paid, and everything that follows from it.
 *
 * **Everything downstream of payment happens here and nowhere else.**
 * ~~A person settling an order in the panel and, in phase B, a webhook from a card
 * processor both arrive at this action, so fulfillment cannot come to differ
 * between them.~~ **Struck 22 October 2026: the panel can no longer mark an order
 * paid.** The webhook and the sweep, through `RecordStripePayment`, are the
 * only roads here, so Stripe is the only way an order becomes paid.
 *
 * ## Running twice
 *
 * It will. Managed queues deliver at least once, and a payment webhook is
 * redelivered as a matter of routine. Surviving that is not discipline here, it
 * is structure: the row is locked, the transition only happens if the order is
 * still pending, and everything else is inside the branch that only a real
 * transition reaches. A second delivery finds the work done and does nothing.
 *
 * ## Ordering, which is the part that is easy to get wrong
 *
 * Access is granted **inside** the transaction, so a failure halfway cannot
 * leave somebody holding a pass for an order that never got marked paid.
 *
 * Mail is queued **after** it commits, for the opposite reason: a message sent
 * inside a transaction that then rolls back has still been sent, and no amount
 * of rolling back unsends it.
 *
 * **Which half of that is a mechanism and which is a position.** Both halves
 * used to read as ordering, and only one of them was enforced by anything. The
 * grant is inside the transaction because the statement is inside the closure,
 * which is the whole of it. The mail waits because `tell()` hands its sends to
 * `DB::afterCommit`, and that holds under a caller that has wrapped this action
 * in a transaction of its own, where merely standing after
 * `DB::transaction(...)` returned does not: the inner transaction is a
 * savepoint, so it returns while the outer one is still open and the mail goes
 * out for an order that is about to roll back. `AskForReading:43-47` says the
 * same thing about the same road.
 */
class MarkOrderPaid
{
    public function __construct(
        private readonly MarkOrderFulfilled $markFulfilled,
        private readonly AskForReading $askForReading,
    ) {}

    /**
     * @return Settlement What this call did, decided inside the row lock.
     *
     * **~~A bool, whether this call is the one that changed anything.~~
     * Replaced 23 October 2026.** Two answers could not tell a redelivery of
     * the payment that settled the order from a second, different payment
     * arriving for an order somebody had already paid for. The second is a
     * customer charged twice, and it used to overwrite the first payment's
     * intent and amount while reporting success.
     *
     * - `Settled`: pending, and this call is the one that paid it.
     * - `AlreadyPaid`: not pending, and `$paidBy` is the intent that settled it.
     *   A success.
     * - `PaidTwice`: not pending, and a different intent. Nothing is written.
     *   Orders are only ever settled by Stripe, so this means two distinct
     *   succeeded intents. Hand-settled staging orders made before 22 October
     *   2026 hold no intent and would read as this, which is test data only.
     * - `Gone`: the row no longer exists.
     */
    public function __invoke(Order $order, PaidBy $paidBy): Settlement
    {
        $settlement = DB::transaction(function () use ($order, $paidBy): Settlement {
            // Locked, so two settlements arriving together cannot both see a
            // pending order and both grant a pass.
            $locked = Order::whereKey($order->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                return Settlement::Gone;
            }

            if ($locked->status !== OrderStatus::Pending) {
                // Nothing is written on either branch. The stored intent is
                // the one that settled the order, and it stays that way.
                return $locked->stripe_payment_intent_id === $paidBy->intentId
                    ? Settlement::AlreadyPaid
                    : Settlement::PaidTwice;
            }

            // **An order with no customer cannot be settled here**, and this
            // is a fault in the caller rather than a case to handle. Since
            // 29 August 2026 an order can be placed holding no identity,
            // because Stripe's hosted page collects the buyer's email after
            // the order exists. `RecordStripePayment` attaches the buyer
            // before it reaches this action, precisely so that everything
            // below has somebody to grant a pass to and somebody to write to.
            // Arriving here without one would spend the one transition that
            // delivers all of it on nobody.
            if ($locked->customer_id === null) {
                throw OrderBelongsToNobody::soItCannotBeSettled($locked);
            }

            // One save, so an order is never paid for an amount nobody wrote
            // down, and the payment that settled it is the one it names.
            $locked->forceFill([
                'status' => OrderStatus::Paid,
                'paid_at' => now(),
                'charged_amount' => $paidBy->amount,
                'stripe_payment_intent_id' => $paidBy->intentId,
            ])->save();

            $this->fulfill($locked);

            return Settlement::Settled;
        });

        if ($settlement === Settlement::Settled) {
            $this->tell($order->refresh()->load('lines.product', 'lines.gift', 'customer'));
        }

        return $settlement;
    }

    /**
     * What being paid for actually delivers.
     *
     * Driven by the product's `fulfillment`, so the One Card Reading becoming
     * AI written in M7 is a data change here and not a new branch.
     */
    private function fulfill(Order $order): void
    {
        $order->load('lines.product', 'lines.gift', 'customer');

        if ($this->grantsAccess($order)) {
            // Granting twice keeps the first date on purpose, so a duplicate
            // settlement cannot rewrite when somebody got in.
            $order->customer->grantViewingRoomAccess();
        }

        // **This is the moment a gift code comes into existence**, and the
        // only one. B6 (#72).
        $this->mintTheGiftCodes($order);

        // **Somebody has asked, and that is now a row.** Since 2 September 2026
        // and `docs/adr/0004-a-reading-is-a-row-of-its-own.md`: a paid order
        // used to be the only record that a person was waiting, and a gift
        // redeemed three months after it was bought makes that untrue. What is
        // handed over is the lines that are waiting, so `AskForReading` never
        // has to ask the question this line has just answered.
        //
        // **Inside the transaction on purpose.** A reading is part of what
        // settling an order does, and an order that is paid with nothing on
        // Jennifer's list to show for it is the failure this row exists to
        // prevent. The mail it queues waits for the commit, which is
        // `AskForReading`'s to arrange and is why the telling is no longer in
        // `tell()` below.
        if ($this->needsAPerson($order)) {
            ($this->askForReading)(
                $this->linesNeedingAPerson($order),
                $order->customer->email,
                $order->customer->name,
            );
        }

        // **Delivered is asked of the order rather than decided a second time
        // here**, since 3 September 2026 and B6.
        //
        // This was `if (! $this->needsAPerson($order))` and an early return,
        // which was the same question as `everythingItOwesHasGone` for as long
        // as the only two shapes were a pass and a reading. **A gift is the
        // shape that parts them.** Nobody has to do anything about a gifted
        // line at settlement, and the order is very much not delivered: it owes
        // a present nobody has redeemed. Left as it was, a gift order was
        // stamped fulfilled in the instant it was paid for.
        //
        // Asking the order is what stops that becoming the third pair of
        // answers that have to agree by hand. `Order::everythingItOwesHasGone`
        // is where "every reading delivered, every gift redeemed and delivered"
        // is written down, ADR 0004 and ADR 0006 both put it that way, and B4
        // built it for the panel's caller. This is the payment path learning to
        // ask the same one.
        //
        // **After the asking, not before.** The readings this settlement
        // creates are part of what the order owes, so a question asked ahead of
        // them would answer about an order that momentarily owed nothing.
        //
        // The stamping itself is `MarkOrderFulfilled`'s, which is the same
        // writer her panel action reaches.
        if ($order->everythingItOwesHasGone()) {
            ($this->markFulfilled)($order);
        }
    }

    /**
     * A code for every present on this order, and the one place they are made.
     *
     * **At settlement and never at placement**, decided in
     * `database/migrations/2026_09_03_000000_a_gift_is_a_line_bought_for_somebody_else.php`
     * and built here. A code minted when the buyer typed the address would be a
     * live bearer credential backed by a payment that may never arrive, one per
     * abandoned checkout. Minting inside this action inherits the row lock, the
     * single transition and the redelivery behaviour that everything else here
     * already rests on, and "what happens to an abandoned gift" stops being a
     * question: the row is inert and nothing can be redeemed with it.
     *
     * **The row already exists and this fills a column in.** B5 writes it at
     * placement holding the four things the buyer typed, so there is nothing to
     * insert and `gifts.order_line_id` being unique is what says there is
     * exactly one present per line.
     *
     * **Only the ones with no code yet.** Redelivery is answered by the
     * transition guard in `__invoke` rather than by this, so a second
     * `payment_intent.succeeded` never reaches this method at all. Writing it
     * this way round anyway means the method is correct because of what it
     * does rather than because of what cannot reach it, and a code somebody has
     * already been emailed is the worst thing in this file to overwrite.
     */
    private function mintTheGiftCodes(Order $order): void
    {
        foreach ($order->gifts() as $gift) {
            if ($gift->isMinted()) {
                continue;
            }

            $gift->mint();
        }
    }

    /**
     * Whether anything on this order is waiting for Jennifer.
     *
     * Asked twice by two callers and answered once, because the two answers are
     * exact opposites and nothing would fail if they came apart. An order
     * fulfilled at payment that she is also told about is work on her list that
     * is already done, and one left waiting that she never hears about is a
     * customer who paid and receives nothing.
     *
     * **The two callers became the two branches of one `if` on 2 September
     * 2026**, when the telling moved out to `AskForReading`. The pair this
     * guards against can no longer be written, since there is one place that
     * decides and no second derivation to drift from it. The reasoning is kept
     * rather than deleted: it is why the shape is a branch and not two
     * questions, and the next person to separate them needs to find out why
     * they were joined.
     *
     * **~~The branch is an `if`/`else` and the two halves are exact
     * opposites.~~ Struck 3 September 2026**, building B6 (#72). They were
     * opposites while an order held only readings and passes. A gift is neither:
     * nothing is waiting for a person and the order still owes a present, so
     * "is anybody waiting" and "has everything gone" became two genuinely
     * different questions asked of the same order. This one still answers the
     * first, and `Order::everythingItOwesHasGone` answers the second. Nothing
     * here derives one from the other any more, which is what keeps them from
     * drifting.
     */
    private function needsAPerson(Order $order): bool
    {
        return $this->linesNeedingAPerson($order)->isNotEmpty();
    }

    /**
     * Which lines on this order are waiting for her, rather than whether any is.
     *
     * A reading is a row per line now, so the answer that used to be a boolean
     * has to name them. `needsAPerson` above is this question with its answer
     * thrown away, and keeping it that way round is what stops a second
     * definition of "waiting for a person" existing.
     *
     * **A gifted line is not one of them, and this is the trap B6 (#72) exists
     * to close.** A gifted `month-ahead` line is `Fulfillment::ManualPdf` like
     * any other, so on `fulfillment` alone a settled gift order reached
     * `AskForReading` and created a reading with **the buyer as querent**,
     * carrying whatever question the buyer had not typed. A gift tells Jennifer
     * nothing until it is redeemed: the person who will ask has not seen the
     * present yet, and asking is what redemption is for.
     *
     * **The clause is here rather than on `fulfillment`**, because a gift is
     * not a different kind of fulfillment. The same reading, written by the
     * same person, arrives by the same road once somebody asks for it. What is
     * different is only when that happens, which is a fact about the line and
     * not about the product.
     *
     * @return Collection<int, OrderLine>
     */
    private function linesNeedingAPerson(Order $order): Collection
    {
        return $order->lines->filter(
            fn ($line): bool => $line->product?->fulfillment === Fulfillment::ManualPdf
                && $line->gift === null,
        )->values();
    }

    /**
     * Whether anything on this order lets somebody into the Viewing Room.
     *
     * Asked twice by two callers and answered once, for the same reason as
     * `needsAPerson` above: two answers that must agree, derived a few lines
     * apart, and nothing failing if they came apart. An order that sends an
     * invitation to a password nobody has any use for, and one that grants a
     * pass while staying silent about the key to it, are the two shapes of that
     * disagreement.
     *
     * **A pass is what the password is for**, which is why `tell()` asks this
     * at all. Decided 30 August 2026 as option B of #46, built as #51: until
     * then every unclaimed buyer was invited to choose one, so the claim mail
     * was the only reason a reading buyer ever heard the word "account".
     *
     * Driven by `fulfillment` rather than by the product's type, so an add-on
     * that grants access is covered without being named here.
     */
    private function grantsAccess(Order $order): bool
    {
        return $order->lines->contains(
            fn ($line): bool => $line->product?->fulfillment === Fulfillment::AccessGrant,
        );
    }

    /**
     * Whether an unspent claim link is already sitting in this person's inbox.
     *
     * **Minting a second one kills the first**, and that is Laravel's
     * behaviour rather than ours: `DatabaseTokenRepository::create()` calls
     * `deleteExisting()` before it inserts. So a customer who bought twice
     * held two receipts, one of them carrying a link that had been dead since
     * the moment the other arrived, with nothing in either saying which.
     *
     * Asked by querying the broker's table rather than through the broker,
     * which offers no way to ask this: `exists()` needs the plaintext token,
     * which only ever existed inside the mail, and `recentlyCreatedToken()`
     * answers about the 60 second throttle rather than the hour a link lives.
     *
     * The consequence, taken knowingly: the second receipt carries no claim
     * link at all. The first one still works for the rest of its hour, and
     * after that a further order mints a fresh link normally. Somebody who has
     * lost both mails asks for a reset link, which sets a first password just
     * as well.
     */
    private function holdsALiveClaimLink(Customer $customer): bool
    {
        $broker = config('auth.passwords.'.config('auth.defaults.passwords'));

        return DB::table($broker['table'])
            ->where('email', $customer->email)
            ->where('created_at', '>', now()->subMinutes($broker['expire']))
            ->exists();
    }

    /**
     * Everybody who needs to know, once the payment is definitely recorded.
     */
    private function tell(Order $order): void
    {
        $customer = $order->customer;

        // **Held back by the mechanism rather than by where this call sits.**
        // Standing after `DB::transaction(...)` returned is only deferral for a
        // caller that opens no transaction of its own. One that does gets a
        // savepoint: the inner transaction returns, `__invoke` sees a real
        // transition, and all of this goes out for an order that is about to
        // roll back. `DB::afterCommit` is what `AskForReading:120` and `:166`
        // already use on the same road, and it runs the callback immediately
        // when nothing is open, so both live callers today are unchanged.
        DB::afterCommit(function () use ($order, $customer): void {
            Mail::to($customer->email)->send(new OrderPaid($order));

            // **The claim link follows the Viewing Room, not the money.** A
            // password is what gets somebody back to the films, so it is asked
            // for on the order that grants them and on no other. A reading is
            // written by hand and arrives as a PDF, and needs no account to
            // read, so a reading buyer never hears the word. An order holding
            // both does send it: it contains a pass, and the pass is what the
            // password is for.
            //
            // Somebody who wants to sign in without ever having bought a pass
            // asks for a reset link, which sets a first password just as well.
            //
            // **A claim link goes only to an account nobody has claimed.**
            // Sending one to an address that already has an account would mean
            // a stranger who knows your email can cause a set-a-password
            // message to land in your inbox. It hands them nothing, and it is
            // still a phishing shaped tool pointed at our own customers.
            //
            // **And only when there is not already one in their inbox**, for
            // the reason in `holdsALiveClaimLink`.
            if ($this->grantsAccess($order) && $customer->isUnclaimed() && ! $this->holdsALiveClaimLink($customer)) {
                Mail::to($customer->email)->send(new SetPasswordLink(
                    $customer,
                    Password::broker('customers')->createToken($customer),
                ));
            }

            // **And whoever was given a present.** B6 (#72), and it is the
            // second of the two mails a settled gift order sends. **The buyer
            // never receives the code**, decided 2 September 2026, so these two
            // messages carry different things to different people out of one
            // payment: the recipient gets the credential, and the buyer gets a
            // receipt naming the address it went to.
            //
            // **After the commit, like everything else in this closure**, and
            // the closure is what makes that true rather than the position of
            // the call. A code emailed out of a transaction that then rolls
            // back is a live credential for a present that does not exist, and
            // no amount of rolling back unsends it.
            //
            // One mail per gift rather than one per order. Two presents bought
            // together are two different people, and a shared message would
            // hand each of them the other's code.
            foreach ($order->gifts() as $gift) {
                Mail::to($gift->recipient_email)->send(new GiftToRedeem($gift));
            }
        });

        // **Telling Jennifer a reading is waiting left here on 2 September
        // 2026**, for `AskForReading`, which now owns the moment a reading
        // starts existing and the message that follows from it. It is reached
        // from `fulfill()` above rather than from here, because the row belongs
        // in the transaction and the mail belongs after it, and one action can
        // arrange both. Redemption reaches the same action months later, which
        // is the whole reason it is no longer payment's.
        //
        // What stays here is what payment itself has to say: the receipt, and
        // the invitation to a password when a pass is what was bought.
    }
}
