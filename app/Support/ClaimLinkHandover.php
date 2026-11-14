<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/**
 * Keeping the receipt's claim token where the confirmation page can ask for it,
 * issue #122.
 *
 * ## The same token as the receipt, never a second one
 *
 * The `customers` broker keeps **one token per address** and stores it
 * **hashed**: `DatabaseTokenRepository::create()` deletes the old row before
 * inserting, and nothing can read the plaintext back afterwards. A second token
 * minted for the page would kill the receipt's link, or be killed by it, and
 * the buyer could end up holding two dead links. So `MarkOrderPaid` hands the
 * one token it mints for the receipt to this class as well, and the page is
 * given exactly what the inbox was given. Using either spends both, which is
 * what a claim is.
 *
 * ## Where the plaintext lives, and for how long
 *
 * **In the cache, encrypted with the application key, for `MINUTES` minutes**,
 * keyed by the order id. The cache is the database store in both deployed
 * environments, so a copy of the database alone yields ciphertext. It is never
 * deleted early: when the token is used or replaced, the broker no longer
 * holds it and `ReadClaimLink` stops handing it over, so a leftover copy is a
 * dead token that expires with its row.
 *
 * ## The bound on what a pay token can do
 *
 * Once this exists, whoever holds a pay token can claim that buyer's account.
 * Four things narrow it, and every one is asked by `ReadClaimLink` on every
 * read as well as here:
 *
 * - **Fifteen minutes after settlement, then never.** The pay token lives as
 *   long as the order; this does not. The buyer is on the confirmation page in
 *   the seconds after paying, and the receipt's link covers the rest of the hour
 * - **Only for an account this order made** (`isFor`). Stripe's hosted page
 *   lets whoever pays type any address, and settlement attaches the order to
 *   the account that address already has. An account with any other order on
 *   it was not made by the person holding this pay token
 * - **Only while the account is unclaimed and the broker still holds this exact
 *   token.** Used, replaced or expired, it is not handed over
 * - **Never for an order whose pay token has been in a path** (see below)
 *
 * ## The same bounds now gate signing in, issue #128
 *
 * **Since 14 November 2026, `POST /orders/enter` signs a first-time pass buyer
 * in by having paid**, decided in #126. `App\Actions\EnterAfterPayment` asks
 * `isFor()`, `MINUTES` and `tokenTravelledInAPath()` from here, so the session
 * and the claim link are bounded by one statement of the rule rather than two.
 * It adds what a session needs and a link did not: a single use, and a request
 * that can hold a session.
 *
 * **The hand-over itself is retired by #128's second step**, once the website
 * stops asking for the link (frontend #185): `keep()`, `token()` and
 * `ReadClaimLink` go, and these bounds stay.
 */
final class ClaimLinkHandover
{
    /**
     * How long after settlement the page may be handed the link.
     *
     * Long enough for a slow webhook and a reload, far shorter than the
     * receipt's sixty minutes, and the whole of the window in which a pay token
     * is worth more than a payment status.
     */
    public const MINUTES = 15;

    public function keep(Order $order, #[\SensitiveParameter] string $token): void
    {
        if ($this->tokenTravelledInAPath($order)) {
            return;
        }

        Cache::put(
            self::tokenKey($order),
            Crypt::encryptString($token),
            now()->addMinutes(self::MINUTES),
        );
    }

    /**
     * The token kept for this order, or null when there is none to give.
     *
     * A value that will not decrypt, after a key rotation say, is treated as
     * absent rather than raised: the receipt still carries the link.
     */
    public function token(Order $order): ?string
    {
        $kept = Cache::get(self::tokenKey($order));

        if (! is_string($kept)) {
            return null;
        }

        try {
            return Crypt::decryptString($kept);
        } catch (DecryptException) {
            return null;
        }
    }

    /**
     * Whether this account is one the page may hand a first password to.
     *
     * **Unclaimed, and holding no order but this one**, paid or not. A pending
     * order counts too, because an order placed with a typed address attaches
     * the account at placement and carries the name that was typed with it.
     * The cost is that a returning unclaimed buyer, a reading bought last month
     * say, gets the receipt's link and not the page's. That is the fallback the
     * page already has, and it is the right side to err on.
     */
    public function isFor(Order $order, Customer $customer): bool
    {
        return $customer->isUnclaimed()
            && ! $customer->orders()->whereKeyNot($order->getKey())->exists();
    }

    /**
     * Noted by the legacy `POST /orders/{pay_token}/pay`, the one route that
     * still carries a pay token in its path.
     *
     * **A path segment is in every access log between the browser and here**,
     * Cloudflare's dashboard included, so an order whose token has travelled
     * that way is never handed a link on the page: the receipt carries it to
     * the inbox instead. Kept for good rather than for a window, because an
     * order never expires and a token logged today is the same token next
     * month. The website moves to `POST /orders/pay` in frontend #170, and the
     * path route goes once that has deployed.
     */
    public function rememberTokenTravelledInAPath(Order $order): void
    {
        Cache::forever(self::pathKey($order), true);
    }

    public function tokenTravelledInAPath(Order $order): bool
    {
        return Cache::has(self::pathKey($order));
    }

    private static function tokenKey(Order $order): string
    {
        return 'claim-link-for-the-page:'.$order->getKey();
    }

    private static function pathKey(Order $order): string
    {
        return 'pay-token-travelled-in-a-path:'.$order->getKey();
    }
}
