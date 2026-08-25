<?php

namespace App\Services\Payments;

use InvalidArgumentException;

/**
 * A payment method this environment does not offer.
 *
 * **Thrown rather than substituted, and that direction is the whole point of
 * the registry.** `config/newsletter.php` falls through to Mailchimp when it
 * does not recognise a driver, because a typo in an env file must not be a
 * quiet way to stop collecting subscribers. Money runs the other way: a typo
 * must not be a quiet way to start charging cards, or to settle an order that
 * nobody paid for.
 *
 * Anything reachable by a customer validates the name before it gets here, so
 * this firing is a fault in the caller rather than a bad request.
 */
class UnknownPaymentMethod extends InvalidArgumentException
{
    /**
     * @param  array<int, string>  $offered
     */
    public static function named(string $method, array $offered): self
    {
        return new self(sprintf(
            'There is no payment method called "%s" here. This environment offers: %s.',
            $method,
            $offered === [] ? 'nothing at all' : implode(', ', $offered),
        ));
    }
}
