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
 * customer and the name is what every message addresses. Stripe collects the
 * email always and the name only sometimes, so the name falls back to the part
 * of the address before the `@` rather than being allowed to be empty: a
 * receipt reading "Hi ," is worse than one reading "Hi jane".
 *
 * Plain scalars, like `StripePayment` beside it, so nothing drags a Stripe
 * client through a queued job.
 */
class BuyerIdentity
{
    private function __construct(
        public readonly string $email,
        public readonly string $name,
    ) {}

    /**
     * **Null when there is no address**, which is not a fault. A payment made
     * before 29 August 2026, or by any route that is not a hosted Checkout
     * Session, has no `customer_details` to read and the order already knows
     * whose it is.
     */
    public static function from(?string $email, ?string $name): ?self
    {
        $email = is_string($email) ? mb_strtolower(trim($email)) : '';

        if ($email === '') {
            return null;
        }

        $name = is_string($name) ? trim($name) : '';

        return new self(
            email: $email,
            name: $name !== '' ? $name : (string) strstr($email.'@', '@', before_needle: true),
        );
    }
}
