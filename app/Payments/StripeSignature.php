<?php

namespace App\Payments;

use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

/**
 * Proving a webhook payload is genuinely Stripe's, before anything reads it.
 *
 * Hard rule 6. **Verified against the raw request body**, so nothing may parse
 * or re-encode it first: a signature covers the exact bytes Stripe sent, and
 * decoding then re-encoding JSON produces different bytes and an identical
 * looking refusal.
 *
 * The verification itself is `stripe-php`'s, deliberately. Stripe's own library
 * is the authority on Stripe's own format, and hand rolling an HMAC here would
 * be the `BunnyStream` mistake with a different vendor: our implementation
 * agreeing with our own test while the real thing refuses everything.
 *
 * **Not a seam.** It is a small verifier the route uses directly, and it names
 * a Stripe concept in every line, so putting an interface of ours in front of
 * it would be a seam in the wrong place by `.claude/external-services.md`.
 */
class StripeSignature
{
    /**
     * @param  string  $payload  The raw request body, untouched.
     * @param  string  $header  The `Stripe-Signature` header, verbatim.
     * @param  int|null  $tolerance  **How old a signature may be, in seconds,
     *                               and null means stripe-php's default.**
     *
     * The endpoint never passes this, and there is deliberately no config knob
     * for it. `stripe-php` verifies with a 300 second tolerance, so a signature
     * captured from Stripe and committed to this repository is permanently
     * expired and the test pinning it has to widen the window to read it. **The
     * only caller that may pass this is that test.** Widening it anywhere else,
     * and especially through config, would switch replay protection off in
     * production with nothing going red.
     *
     * @throws MissingStripeSigningSecret
     * @throws SignatureVerificationException
     * @throws UnexpectedValueException
     */
    public function verify(string $payload, string $header, ?int $tolerance = null): Event
    {
        return Webhook::constructEvent(
            $payload,
            $header,
            $this->secret(),
            $tolerance ?? Webhook::DEFAULT_TOLERANCE,
        );
    }

    /**
     * @throws MissingStripeSigningSecret
     */
    private function secret(): string
    {
        $secret = config('services.stripe.webhook_secret');

        if (! is_string($secret) || trim($secret) === '') {
            throw MissingStripeSigningSecret::inThisEnvironment();
        }

        return $secret;
    }
}
