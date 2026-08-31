<?php

namespace App\Payments;

/**
 * Who paid, as Stripe collected it, by whichever road took the money.
 *
 * ~~As Stripe's hosted page collected it.~~ **Widened 29 August 2026.** The
 * hosted page puts it on a Checkout Session's `customer_details` and the wallet
 * puts it on the intent's own charge, `latest_charge.billing_details`. Both
 * arrive here as the same pair, which is why the wallet road needed a second
 * resolver and no change to this class.
 *
 * **A pair, because an account is made from both.** The address identifies the
 * customer. The name is what messages address them by when there is one.
 *
 * ~~Stripe collects the email always and the name only sometimes, so the name
 * falls back to the part of the address before the `@` rather than being
 * allowed to be empty: a receipt reading "Hi ," is worse than one reading
 * "Hi jane".~~ **Reversed 30 August 2026, issue #52.** That was a true choice
 * between two bad options and it stopped being the choice available. The
 * nameless greeting now reads "Hello," through `App\Support\Greeting`, which
 * beats both of them, so nothing here has to invent anything.
 *
 * **The name is nullable and null is the honest answer.** `jane` is the routing
 * half of an address, not a name, and this application never asked for one on
 * the road the customer took: the hosted page's name is a text field the buyer
 * typed and card authorisation carries no name check, and the wallet is asked
 * for an address rather than a billing contact.
 *
 * **A name that did arrive is still used to address somebody**, unverified and
 * all. That is not an oversight: nothing can separate a typed placeholder from
 * a typed name, so refusing the unverified ones would mean refusing every name
 * this application has. What is refused is inventing one that never arrived.
 *
 * Plain scalars, like `StripePayment` beside it, so nothing drags a Stripe
 * client through a queued job.
 */
class BuyerIdentity
{
    private function __construct(
        public readonly string $email,
        public readonly ?string $name,
    ) {}

    /**
     * **Null when there is no address**, which is not a fault. A payment made
     * before 29 August 2026, or by any route that is not a hosted Checkout
     * Session, has no `customer_details` to read and the order already knows
     * whose it is.
     *
     * **A blank name is no name**, since a wallet that collected no billing
     * contact and a hosted page whose field was left alone are the same fact
     * arriving as `null` and as `''`.
     */
    public static function from(?string $email, ?string $name): ?self
    {
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';

        if ($email === '') {
            return null;
        }

        $name = trim($name ?? '');

        return new self(
            email: $email,
            name: $name !== '' ? $name : null,
        );
    }
}
