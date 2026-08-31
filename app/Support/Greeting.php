<?php

namespace App\Support;

/**
 * How a message opens, for a reader whose name we may never have been told.
 *
 * **Nothing on the road a customer actually takes asks them their name.** Since
 * ADR 0002 moved checkout to Stripe's hosted page, `StoreOrderRequest` only
 * requires one from a guest who supplies an address up front, which that road
 * never does. So the name on a customer created at settlement is whatever
 * Stripe collected: a text field the buyer typed on the hosted page, or a
 * billing detail the wallet may not have collected at all. See
 * `App\Payments\BuyerIdentity`.
 *
 * **A placeholder cannot be detected, and this does not try.** Card
 * authorisation carries no name check, so Stripe never verifies the typed name
 * against the issuer, and "Card Holder Name" and "Jennifer Eisenberger" are the
 * same kind of value: free text nobody checked. A blocklist would catch the one
 * already seen in a receipt and not the next one. Issue #52 is what the
 * unverifiable half of that costs, and what is fixed here is the half with a
 * right answer.
 *
 * **Here rather than in three views**, because the alternative was the same
 * ternary copied into `order-paid`, `set-password` and `password-reset`, and
 * the reasoning above then has nowhere to live that a reader would find.
 */
final class Greeting
{
    /**
     * The opening line, comma and all.
     *
     * **The comma is inside this rather than left in the view**, since where it
     * goes is the whole question: `Hello {!! $name !!},` is what sent
     * "Hello ,", and a view holding a bare comma is one careless interpolation
     * away from sending it again.
     *
     * **Empty and whitespace count as no name, not just null.** `''` is what
     * `OrderController` wrote into a NOT NULL column before this issue, so rows
     * of that shape can predate the migration that made the column nullable,
     * and a name pasted into a form arrives padded.
     */
    public static function line(?string $name): string
    {
        $name = trim($name ?? '');

        return $name === '' ? 'Hello,' : "Hello {$name},";
    }
}
