<?php

namespace App\Actions;

use App\Enums\EntryOutcome;
use App\Enums\Fulfillment;
use App\Models\Order;
use App\Support\ClaimLinkHandover;
use Illuminate\Support\Facades\Cache;

/**
 * Whether the browser holding a pay token may be signed in by having paid,
 * issue #128, decided in #126 on 14 November 2026.
 *
 * **Every pass buyer, since 24 November 2026 (#135).** ~~Only an account this
 * payment made: unclaimed, holding no other order (decision 1 of #126).~~
 * Reversed by decision: a repeat buyer is treated exactly like a new one, so
 * the client's flow (pay, land in the Viewing Room signed in, welcome email
 * separately) holds for everybody who buys the pass.
 *
 * ## The risk this accepts, and what reopens it
 *
 * **The checkout email is typed into Stripe and nobody has verified it.**
 * Somebody who pays with another person's address is signed in to that
 * person's existing account, not only to a new account in their name. What
 * that session can reach today is all of it:
 *
 * - `GET /me`: `id`, `name`, `email`, `created_at`, `has_viewing_room_access`
 * - the card videos
 * - logout
 *
 * The owner ends the session by resetting their password: Sanctum's
 * `AuthenticateSession` signs it out on its next request
 * (`EnterAfterPaymentTest`, "squatting on a claimed account"). The owner also
 * receives a receipt and a welcome email for a purchase they did not make,
 * which is their signal. The price of a pass is what stands in the way.
 *
 * **Any new `auth:sanctum` route reopens this decision**: order history, saved
 * readings, profile edits, anything that shows or changes more than the list
 * above. Whoever adds one decides again, on the record, whether a payment may
 * still sign somebody in to an account it did not make. `CONTEXT.md` says the
 * same under **Entering after payment**.
 *
 * **It decides, and the controller signs in.** Every bound is asked on every
 * request, in the order #128 numbers them, and the single use is the last thing
 * taken, so no answer short of signing somebody in ever spends it.
 *
 * **The bounds are `ClaimLinkHandover`'s, reused rather than restated.** #122
 * worked out when a pay token may be worth an account, for handing the page a
 * claim link. This hands the page the session the claim link would have ended
 * in, so the same bounds hold for the same reasons, and that class carries them.
 * ~~`isFor()` among them~~, removed with gate 3 on 24 November 2026 (#135).
 *
 * **Every refusal is silent.** The pay token is a credential and nothing on this
 * path writes it, or anything keyed on it, to a log.
 */
class EnterAfterPayment
{
    public function __construct(private readonly ClaimLinkHandover $handover) {}

    /**
     * @param  bool  $canHoldSession  Whether the request arrived with a cookie
     *                                session to sign in, which only the
     *                                controller can see
     */
    public function __invoke(Order $order, bool $canHoldSession): EntryOutcome
    {
        $order->loadMissing('lines.product', 'customer');

        // 1. Settled by `MarkOrderPaid`. The card road paints "received" before
        // the webhook lands, so this is the one answer that means ask again.
        if (! $order->isPaid()) {
            return EntryOutcome::NotYet;
        }

        // 2. A Viewing Room pass line, by the `fulfillment` test
        // `MarkOrderPaid::grantsAccess()` asks. A reading buyer has nothing in
        // the Viewing Room to be signed in for.
        $grantsAccess = $order->lines->contains(
            fn ($line): bool => $line->product?->fulfillment === Fulfillment::AccessGrant,
        );

        if (! $grantsAccess) {
            return EntryOutcome::Never;
        }

        // 3. ~~An account this payment made: unclaimed, holding no other order.
        // Decision 1 of #126. The checkout email is typed into Stripe and nobody
        // has verified it, so an existing account is never handed to whoever
        // typed its address.~~ Reversed 24 November 2026, #135: any customer,
        // claimed or not, with or without other orders. The risk that accepts
        // is in this class's docblock. A paid order always has a customer, and
        // the null check stays because the database cannot say so to PHP.
        if ($order->customer === null) {
            return EntryOutcome::Never;
        }

        // 4. Within `ClaimLinkHandover::MINUTES` of settlement, then never. The
        // window the retired hand-over used (#128's second step), kept as is.
        if ($order->paid_at === null
            || $order->paid_at->copy()->addMinutes(ClaimLinkHandover::MINUTES)->isPast()) {
            return EntryOutcome::Never;
        }

        // 5. A pay token that has been in a path is in an access log.
        if ($this->handover->tokenTravelledInAPath($order)) {
            return EntryOutcome::Never;
        }

        // 7, asked before 6 on purpose. A request with no session to sign in
        // would otherwise spend the buyer's one entry on nothing.
        if (! $canHoldSession) {
            return EntryOutcome::Never;
        }

        // 6. Single use, taken only once every bound above has held. `add`
        // writes only when nothing is there, and the database store does it in
        // one insert, so two browsers racing the same token cannot both win.
        // Kept for the window, after which bound 4 refuses on its own.
        $first = Cache::add(
            'entered-after-payment:'.$order->getKey(),
            true,
            now()->addMinutes(ClaimLinkHandover::MINUTES),
        );

        return $first ? EntryOutcome::Enter : EntryOutcome::Never;
    }
}
