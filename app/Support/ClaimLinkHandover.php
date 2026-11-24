<?php

namespace App\Support;

use App\Models\Order;
use Illuminate\Support\Facades\Cache;

/**
 * When a pay token is worth more than a payment status: the bounds that gate
 * signing a pass buyer in, issue #128. ~~A first-time pass buyer~~ until #135
 * entered every pass buyer, 24 November 2026.
 *
 * **The name is historical.** This class was written for #122, to keep the
 * receipt's claim token where the confirmation page could ask for it. That
 * hand-over is retired (14 November 2026, #128's second step) and only its
 * bounds remain, asked by `App\Actions\EnterAfterPayment`. The name stays, by
 * decision on 14 November 2026, because renaming it is churn against open work (#124's
 * PR #125) for no change in behaviour. `CONTEXT.md` records the same.
 *
 * ## ~~The same token as the receipt, never a second one~~
 *
 * ~~`MarkOrderPaid` hands the one token it mints for the receipt to this class
 * as well, and the page is given exactly what the inbox was given.~~ Retired
 * 14 November 2026, #128. `keep()`, `token()` and `ReadClaimLink` are gone,
 * `MarkOrderPaid` keeps nothing here, and `POST /orders/status` answers
 * ~~`set_password_link: "none"` on every 200~~ its status alone (the claim
 * link keys removed 14 November 2026, #131). ~~The receipt still carries the
 * link~~ The welcome email carries the link since #135 (24 November 2026),
 * minted exactly as before, and it is the buyer's only copy.
 *
 * ## ~~Where the plaintext lives, and for how long~~
 *
 * ~~In the cache, encrypted with the application key, for `MINUTES` minutes,
 * keyed by the order id (`claim-link-for-the-page:{id}`).~~ Retired 14
 * November 2026, #128. Nothing writes that key now. A copy kept just before
 * the deploy expires with its row within `MINUTES`, and nothing reads it.
 *
 * ## The bound on what a pay token can do
 *
 * ~~Once this exists, whoever holds a pay token can claim that buyer's
 * account.~~ Since #128, whoever holds a pay token can be signed in to that
 * buyer's account, once. Three things here narrow it, and
 * `EnterAfterPayment` asks every one of them on every request:
 *
 * - **Fifteen minutes after settlement, then never** (`MINUTES`). The pay token
 *   lives as long as the order; this does not. The buyer is on the
 *   confirmation page in the seconds after paying, and the welcome email's
 *   link (the receipt's until #135) covers the rest of the hour
 * - ~~**Only for an account this order made** (`isFor`). Stripe's hosted page
 *   lets whoever pays type any address, and settlement attaches the order to
 *   the account that address already has. An account with any other order on
 *   it was not made by the person holding this pay token~~. **Dropped 24
 *   November 2026, #135, by decision**: every pass buyer is entered, an
 *   existing account included. The risk that accepts is written in
 *   `EnterAfterPayment`'s docblock. **`isFor()` is removed, not left unused**,
 *   because `EnterAfterPayment` was its only caller and a bound nobody asks
 *   reads as one that still holds
 * - ~~**Only while the account is unclaimed and the broker still holds this
 *   exact token.**~~ The token half retired with the hand-over, 14 November
 *   2026. ~~Unclaimed is still asked, inside `isFor`~~ and the unclaimed half
 *   with `isFor`, 24 November 2026
 * - **Never for an order whose pay token has been in a path** (see below)
 *
 * ## The same bounds gate signing in, issue #128
 *
 * **Since 14 November 2026, `POST /orders/enter` signs a ~~first-time~~ pass
 * buyer in by having paid**, decided in #126. Every pass buyer since 24
 * November 2026, #135. `App\Actions\EnterAfterPayment` asks
 * ~~`isFor()`,~~ `MINUTES` and `tokenTravelledInAPath()` from here, so the rule is
 * stated once (`isFor()` removed 24 November 2026, #135). It adds what a
 * session needs and a link did not: a single use,
 * and a request that can hold a session.
 *
 * ~~**The hand-over itself is retired by #128's second step**, once the
 * website stops asking for the link (frontend #185).~~ Done 14 November 2026.
 */
final class ClaimLinkHandover
{
    /**
     * How long after settlement a pay token may sign its buyer in.
     *
     * ~~How long after settlement the page may be handed the link.~~ The same
     * fifteen minutes since #128, for the same reason: long enough for a slow
     * webhook and a reload, far shorter than the welcome email's sixty minutes, and
     * the whole of the window in which a pay token is worth more than a
     * payment status.
     */
    public const MINUTES = 15;

    /**
     * Noted by the legacy `POST /orders/{pay_token}/pay`, the one route that
     * still carries a pay token in its path.
     *
     * **A path segment is in every access log between the browser and here**,
     * Cloudflare's dashboard included, so an order whose token has travelled
     * that way is never ~~handed a link on the page~~ signed in by
     * `POST /orders/enter` (since #128): the welcome email (the receipt until
     * #135) carries the link to the inbox instead. Kept for good rather than for a window, because an order
     * never expires and a token logged today is the same token next month. The
     * website moves to `POST /orders/pay` in frontend #170, and the path route
     * goes once that has deployed.
     */
    public function rememberTokenTravelledInAPath(Order $order): void
    {
        Cache::forever(self::pathKey($order), true);
    }

    public function tokenTravelledInAPath(Order $order): bool
    {
        return Cache::has(self::pathKey($order));
    }

    private static function pathKey(Order $order): string
    {
        return 'pay-token-travelled-in-a-path:'.$order->getKey();
    }
}
