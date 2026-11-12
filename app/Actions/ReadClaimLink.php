<?php

namespace App\Actions;

use App\Enums\Fulfillment;
use App\Models\Order;
use App\Payments\ClaimLinkForThePage;
use App\Support\ClaimLinkHandover;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\Password;

/**
 * Whether the confirmation page gets the claim link, issue #122.
 *
 * **It reads, and mints nothing.** The token is the one `MarkOrderPaid` minted
 * for the receipt, kept by `ClaimLinkHandover`, which carries why. This
 * endpoint is polled by a browser, and a poll that minted would kill the link
 * in the buyer's inbox every time it ran.
 *
 * **"Not yet" rather than minting on demand.** The card road paints `received`
 * before the webhook has said who paid, so the first ask usually finds a
 * pending order with no customer. Minting here would mean resolving the buyer
 * ahead of the webhook and racing it for the broker's one row per address. The
 * page asks again instead, briefly, and falls back when it gives up.
 *
 * **Every refusal is `none`, and none of them writes a log line.** The pay token
 * is the authority here and a credential; nothing on this path records it.
 */
class ReadClaimLink
{
    public function __construct(private readonly ClaimLinkHandover $handover) {}

    public function __invoke(Order $order): ClaimLinkForThePage
    {
        $order->loadMissing('lines.product', 'customer');

        // The same `fulfillment` test `MarkOrderPaid::grantsAccess()` asks, so
        // the page is offered a password exactly when the receipt is. A reading
        // order and a gift order never are.
        $grant = $order->lines
            ->first(fn ($line): bool => $line->product?->fulfillment === Fulfillment::AccessGrant)
            ?->product;

        if ($grant === null || $this->handover->tokenTravelledInAPath($order)) {
            return ClaimLinkForThePage::none();
        }

        // Asked before settlement as well, so a buyer who signed in at checkout,
        // or typed the address of an account with history, is told "none" at
        // once rather than "not yet" for as long as the page keeps asking.
        $customer = $order->customer;

        if ($customer !== null && ! $this->handover->isFor($order, $customer)) {
            return ClaimLinkForThePage::none();
        }

        if ($order->isPending()) {
            return ClaimLinkForThePage::notYet();
        }

        if (! $order->isPaid() || $customer === null || $order->paid_at === null
            || $order->paid_at->copy()->addMinutes(ClaimLinkHandover::MINUTES)->isPast()) {
            return ClaimLinkForThePage::none();
        }

        $token = $this->handover->token($order);

        // Settled and eligible with nothing kept yet: the moment between the
        // order's commit and the mint in `MarkOrderPaid::tell()`. Asking again
        // finds it, and the window above ends the asking if it never comes.
        if ($token === null) {
            return ClaimLinkForThePage::notYet();
        }

        // Used, replaced by a newer mint, or expired. The page is never handed
        // a link the set-password endpoint would refuse.
        if (! Password::broker('customers')->tokenExists($customer, $token)) {
            return ClaimLinkForThePage::none();
        }

        return ClaimLinkForThePage::ready(
            FrontendUrl::setPassword($customer->email, $token, $grant->key),
        );
    }
}
