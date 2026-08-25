<?php

use App\Payments\StripePayment;
use App\Payments\StripeSignature;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;

/**
 * A known-answer vector, captured from Stripe by hand.
 *
 * **This is the only file about Stripe's signature format with any authority.**
 * `tests/Feature/Api/StripeWebhookTest.php` signs its own payloads and proves
 * our plumbing, which is right and proves nothing about Stripe: it agrees with
 * our code whatever our code does. Only Stripe can say a Stripe signature is
 * valid, so Stripe is asked, by hand, once.
 *
 * **Not captured yet.** See `tests/Fixtures/stripe/README.md` for exactly how,
 * and note that this file skips until it is there rather than passing quietly.
 * A test that never executes looks exactly like one that passes, which is the
 * lesson of 23 August 2026, and the M4 lesson underneath it is that a green
 * suite over a dead integration is how playback shipped broken for five days.
 *
 * ## The tolerance, and why widening it here is safe
 *
 * `stripe-php` verifies with a 300 second tolerance, so a signature committed to
 * a repository is permanently expired the moment it is committed. Reading it
 * back at all means widening the window, and **this is the only caller in the
 * codebase allowed to pass that argument.** The endpoint passes nothing, which
 * is proved separately in `StripeWebhookTest` so that widening it here can
 * never quietly widen it there.
 *
 * ## Regenerating this
 *
 * **Send a new event through the live Stripe CLI and prove it is accepted
 * before changing a single byte below.** Do not recompute the signature from
 * the code this file exists to check.
 */
function stripeVector(string $name): ?string
{
    $path = base_path('tests/Fixtures/stripe/payment-intent-succeeded.'.$name);

    return is_file($path) ? file_get_contents($path) : null;
}

function stripeVectorIsMissing(): bool
{
    return stripeVector('json') === null
        || stripeVector('sig') === null
        || stripeVector('secret') === null;
}

const VECTOR_MISSING = 'No Stripe vector has been captured. See tests/Fixtures/stripe/README.md. '
    .'Until it is, nothing in this repository proves Stripe accepts our signature check.';

/**
 * A hundred years, because the fixture is expired by design and its age is not
 * what is being tested. What is being tested is the signature itself.
 */
const FOREVER = 3155760000;

beforeEach(function () {
    config()->set('services.stripe.webhook_secret', trim((string) stripeVector('secret')));
});

it('verifies a signature Stripe genuinely produced', function () {
    $event = (new StripeSignature)->verify(
        stripeVector('json'),
        trim(stripeVector('sig')),
        tolerance: FOREVER,
    );

    expect($event->type)->toBe('payment_intent.succeeded')
        ->and($event->data->object)->toBeInstanceOf(PaymentIntent::class);
})->skip(fn (): bool => stripeVectorIsMissing(), VECTOR_MISSING);

/**
 * The signature covers the bytes, not the meaning. One character changed in a
 * payload Stripe really signed has to be refused, and this is the version of
 * that assertion where the signature was not ours to begin with.
 */
it('refuses the captured payload once a byte of it changes', function () {
    expect(fn () => (new StripeSignature)->verify(
        stripeVector('json').' ',
        trim(stripeVector('sig')),
        tolerance: FOREVER,
    ))->toThrow(SignatureVerificationException::class);
})->skip(fn (): bool => stripeVectorIsMissing(), VECTOR_MISSING);

/**
 * What we read out of a real payload, rather than out of one we wrote. Field
 * names in somebody else's JSON are the thing a hand written fixture gets
 * plausibly wrong.
 */
it('reads a real payload the way the settlement path expects', function () {
    $event = (new StripeSignature)->verify(
        stripeVector('json'),
        trim(stripeVector('sig')),
        tolerance: FOREVER,
    );

    $payment = StripePayment::from($event->data->object);

    expect($payment->succeeded())->toBeTrue()
        ->and($payment->id)->toStartWith('pi_')
        ->and($payment->amount)->toBeGreaterThan(0)
        ->and($payment->currency)->not->toBe('');
})->skip(fn (): bool => stripeVectorIsMissing(), VECTOR_MISSING);
