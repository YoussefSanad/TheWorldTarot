<?php

namespace App\Payments;

use RuntimeException;

/**
 * This environment has no signing secret, so nothing can be verified.
 *
 * **Everything is refused, which is the only safe direction.** Failing open on
 * a webhook is failing open on granting paid access for free, and an endpoint
 * that trusts an unverifiable payload is a way for anybody to mark any order
 * paid.
 *
 * It reads exactly like a forged request from the outside, and it has to: the
 * difference is our configuration, and telling a caller which of the two went
 * wrong tells them something about us. It is loud in the log instead.
 *
 * The secret is issued **per endpoint** in the Stripe dashboard, not per
 * account, so staging and production hold different values.
 */
class MissingStripeSigningSecret extends RuntimeException
{
    public static function inThisEnvironment(): self
    {
        return new self(
            'No Stripe signing secret is configured, so no webhook can be verified. '
            .'Set STRIPE_WEBHOOK_SECRET to the whsec_ value from this environment\'s endpoint in the Stripe dashboard.',
        );
    }
}
