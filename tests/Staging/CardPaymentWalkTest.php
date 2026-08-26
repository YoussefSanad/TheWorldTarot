<?php

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Tests\Staging\Target;

/**
 * A real Stripe test payment, against a deployed environment, moving a real
 * order.
 *
 * **This is the only thing in the repository that proves payment works.** The
 * feature suite drives a substituted StripeClient, which agrees with our code
 * whatever our code does, and the captured signature vector proves the format
 * of one webhook body and nothing about whether an endpoint is reachable. The
 * question neither can answer is whether a customer on the real site can hand
 * over a card and receive what they bought, and that question has exactly one
 * honest way to be asked.
 *
 * The M4 lesson in its usual clothes: a green suite over a dead integration is
 * how playback shipped broken for five days.
 *
 * ## Nothing here shares a line of code with the application
 *
 * Stripe is reached with raw HTTP rather than through `stripe/stripe-php`, even
 * though the application holds that library and it would be easier. If the
 * library were the broken thing, a walk built on it would agree with the
 * application about being broken. This suite is worth having only because it
 * shares nothing, and that includes the client.
 *
 * ## The publishable key, and why it is that one
 *
 * Confirming a PaymentIntent with a **publishable** key plus the client secret
 * is exactly what Stripe.js does in the customer's browser. It is the closest
 * thing to a real customer reachable without a browser, and a publishable key
 * that leaks is a publishable key.
 *
 * A live key is refused outright. Confirming payments with one charges cards
 * belonging to real people.
 *
 * ## Running it
 *
 * ```bash
 * STAGING_URL=https://staging-api.theworldtarot.com \
 * STAGING_CARD=1 \
 * STAGING_STRIPE_PUBLISHABLE=pk_test_... \
 * composer test:staging
 * ```
 *
 * Without `STAGING_CARD=1` every test in this file skips with the reason
 * attached. **Read a skip as a skip and count them**, per the 23 August 2026
 * lesson: a test that never executes looks exactly like one that passes.
 *
 * ## What it cannot prove, and who has to
 *
 * **The 3D Secure challenge itself.** A card that requires authentication gets
 * as far as Stripe saying so, which is the assertion below, and the challenge
 * after that is an iframe a person completes. Whether that sheet renders,
 * whether the customer comes back, and whether the wallet buttons appear at all
 * are the frontend partner's to confirm in a browser.
 *
 * **Apple Pay and Google Pay.** They are the same PaymentIntent as the card
 * below, so this proves the intent they confirm against. Whether the buttons
 * appear needs a registered payment method domain and a real device, and
 * neither is reachable from curl.
 */
beforeEach(function (): void {
    if ($reason = Target::unavailable()) {
        $this->markTestSkipped($reason);
    }

    if ($reason = cardWalkUnavailable()) {
        $this->markTestSkipped($reason);
    }
});

/**
 * Why the card walk cannot run, or null if it can.
 */
function cardWalkUnavailable(): ?string
{
    if (getenv('STAGING_CARD') !== '1') {
        return 'The card walk takes a real Stripe test payment. Set STAGING_CARD=1 to run it. '
            .'Until somebody does, nothing here has proved that payment works on any environment.';
    }

    $key = (string) getenv('STAGING_STRIPE_PUBLISHABLE');

    if ($key === '') {
        return 'STAGING_STRIPE_PUBLISHABLE is not set, so a payment cannot be confirmed the way a browser would. '
            .'Use the test mode publishable key from the same Stripe account this environment is configured with.';
    }

    // **The one guard rail that matters here.** A live key confirms payments
    // against cards belonging to real people, and no test card is a test card
    // in live mode.
    if (! str_starts_with($key, 'pk_test_')) {
        return 'STAGING_STRIPE_PUBLISHABLE is not a test mode key. Confirming payments with a live key charges '
            .'real cards. Refusing to run.';
    }

    return null;
}

function stripePublishableKey(): string
{
    return (string) getenv('STAGING_STRIPE_PUBLISHABLE');
}

function cardWalkCurrency(): string
{
    $currency = getenv('STAGING_CURRENCY');

    return is_string($currency) && $currency !== '' ? $currency : 'GBP';
}

function cardWalkPassKey(): string
{
    $key = getenv('STAGING_PASS_KEY');

    return is_string($key) && $key !== '' ? $key : 'viewing-room-pass';
}

/**
 * The intent a client secret belongs to.
 *
 * `pi_3Abc_secret_xyz` names `pi_3Abc`. Split rather than parsed, because the
 * only part of the shape being relied on is the separator Stripe documents.
 */
function intentIdIn(string $clientSecret): string
{
    return explode('_secret_', $clientSecret, 2)[0];
}

/**
 * Confirms a payment the way the customer's browser would.
 *
 * Raw HTTP to Stripe, with a publishable key and the client secret, which is
 * the call Stripe.js makes. Nothing of ours is involved on this side.
 *
 * **This reaches the real Stripe API, and that is deliberate.** It is the one
 * carve-out in `.claude/testing.md`, granted 26 August 2026 and granted to this
 * directory alone. Test mode only, off unless `STAGING_CARD=1` is asked for,
 * never against production, never in CI. Read that section before copying this
 * shape anywhere else, because everywhere else the rule is still absolute.
 *
 * The reason it is worth the exception, in one line: the feature suite drives a
 * substituted `StripeClient`, which agrees with our code whatever our code does,
 * and that is precisely how M4 shipped video playback that had never once
 * worked. Only Stripe can say a payment succeeded.
 */
function confirmAtStripe(string $clientSecret, string $paymentMethod): ResponseInterface
{
    return (new Client(['http_errors' => false, 'timeout' => 30]))->post(
        'https://api.stripe.com/v1/payment_intents/'.intentIdIn($clientSecret).'/confirm',
        [
            'headers' => [
                'Authorization' => 'Bearer '.stripePublishableKey(),
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'client_secret' => $clientSecret,
                'payment_method' => $paymentMethod,

                // Where a card needing authentication would send the customer
                // back to. Never followed here, and required by Stripe before
                // it will start a flow that could leave the page.
                'return_url' => Target::origin().'/',
            ],
        ],
    );
}

/**
 * An account, an order for the pass, and a client secret to pay it with.
 *
 * @return array{account: array{email: string, token: string}, order: array<string, mixed>, secret: string}
 */
function orderReadyToPay(string $tag): array
{
    $client = Target::client();

    // Its own account each time, because one of these ends up holding a pass
    // and the rest of this suite relies on the shared account not having one.
    $account = Target::register($tag);

    $placed = $client->post('api/v1/orders', [
        'headers' => Target::as($account),
        'json' => [
            'currency' => cardWalkCurrency(),
            'lines' => [['product' => cardWalkPassKey()]],
        ],
    ]);

    Target::assertNotThrottled($placed, 'orders');

    if ($placed->getStatusCode() === 422) {
        test()->markTestSkipped(
            cardWalkPassKey().' cannot be bought in '.cardWalkCurrency().' here. Publish it and price it above '
            .'zero in the panel first. Response: '.$placed->getBody()
        );
    }

    expect($placed->getStatusCode())->toBe(201);

    $order = Target::json($placed);

    expect($order['status'])->toBe('pending')
        ->and($order['pay_token'] ?? null)->toBeString();

    $paying = $client->post('api/v1/orders/'.$order['pay_token'].'/pay', [
        'headers' => Target::as($account),
        'json' => ['method' => 'stripe'],
    ]);

    Target::assertNotThrottled($paying, 'orders-pay');

    if ($paying->getStatusCode() !== 200) {
        test()->fail(
            'The pay endpoint answered '.$paying->getStatusCode().' rather than a client secret. '
            .'Stripe is probably not configured on this environment, which needs BOTH STRIPE_SECRET and '
            .'STRIPE_WEBHOOK_SECRET filled before the stripe method is offered at all. Either one missing looks '
            .'exactly like this. Response: '.$paying->getBody()
        );
    }

    // **A credential with this order's money attached**, so it must never sit
    // in a cache anywhere between here and the customer.
    expect($paying->getHeaderLine('Cache-Control'))->toContain('no-store');

    $instruction = Target::json($paying);

    expect($instruction['type'])->toBe('client_secret')
        ->and($instruction['client_secret'] ?? null)->toBeString();

    return ['account' => $account, 'order' => $order, 'secret' => (string) $instruction['client_secret']];
}

it('takes a card and delivers what was bought, with nobody touching the panel', function (): void {
    $client = Target::client();
    ['account' => $account, 'order' => $order, 'secret' => $secret] = orderReadyToPay('card');
    $asCustomer = Target::as($account);

    expect(Target::json($client->get('api/v1/me', ['headers' => $asCustomer]))['has_viewing_room_access'])->toBeFalse();

    Target::say('Order #'.$order['id'].' placed for '.$account['email'].'. Paying it with a test card.');

    $confirmed = confirmAtStripe($secret, 'pm_card_visa');
    $body = Target::json($confirmed);

    if ($confirmed->getStatusCode() !== 200) {
        test()->fail(
            'Stripe refused the confirmation with '.$confirmed->getStatusCode().'. This is Stripe talking, not us, '
            .'so read its message before touching anything here: '.$confirmed->getBody()
        );
    }

    // Stripe's own word that the money moved. Everything after this is about
    // whether the deployed environment noticed.
    //
    // **`amount` rather than `amount_received`**, corrected 26 August 2026 on
    // this file's first execution. A publishable key is given a **limited**
    // PaymentIntent, which carries amount, currency and status and withholds
    // the settlement fields: amount_received and latest_charge need a secret
    // key. Confirming the way Stripe.js does was always going to get the
    // limited object, so the original assertion asked this call for something
    // it can never answer and read as a failed payment on a payment that
    // succeeded.
    //
    // Nothing weakens. `amount` is what the customer is being charged, checked
    // against our own total, and `succeeded` is Stripe saying it took it. That
    // the money reached the account is proved by the pass appearing below,
    // which only the webhook can cause.
    expect($body['status'] ?? null)->toBe('succeeded')
        ->and($body['amount'] ?? null)->toBe($order['total_amount'])
        ->and(strtoupper((string) ($body['currency'] ?? '')))->toBe(cardWalkCurrency());

    Target::say('Stripe took the payment. Watching for the pass to appear.');

    $granted = false;
    $waited = 0;

    while ($waited < 120) {
        sleep(5);
        $waited += 5;

        $me = Target::json($client->get('api/v1/me', ['headers' => $asCustomer]));

        if (($me['has_viewing_room_access'] ?? false) === true) {
            $granted = true;

            break;
        }
    }

    // **Inside two minutes, which can only be the webhook.** The sweep leaves
    // an order alone for its first fifteen minutes, so nothing it does could
    // have caused this. That makes the wait itself the assertion: it proves the
    // endpoint is registered at Stripe for this environment, reachable from
    // outside, and carrying a signing secret that matches. Nothing else in the
    // repository can prove any of those three.
    expect($granted)->toBeTrue(
        'The payment succeeded at Stripe and the pass never appeared within two minutes. The sweep does not touch '
        .'an order for fifteen minutes, so this is the webhook: check that the endpoint is registered at Stripe '
        .'for this environment, that its URL is right, and that STRIPE_WEBHOOK_SECRET here matches the one Stripe '
        .'shows for that endpoint. A customer has been charged and has received nothing.'
    );

    Target::say('Paid by card and delivered in '.$waited.' seconds, with nobody in the panel.');

    // A second attempt on a settled order creates nothing. Observed from
    // outside, which is the only place the difference is visible.
    $again = $client->post('api/v1/orders/'.$order['pay_token'].'/pay', [
        'headers' => $asCustomer,
        'json' => ['method' => 'stripe'],
    ]);

    expect($again->getStatusCode())->toBe(200)
        ->and(Target::json($again)['type'])->toBe('nothing_to_pay');
});

/**
 * **A decline is a retry, not an ending.** The no-expiry decision says the order
 * stays payable, and the customer comes back with another card. An order this
 * path had cancelled would be a sale refused on the customer's behalf.
 */
it('refuses a declined card and leaves the order payable', function (): void {
    $client = Target::client();
    ['account' => $account, 'order' => $order, 'secret' => $secret] = orderReadyToPay('declined');
    $asCustomer = Target::as($account);

    $confirmed = confirmAtStripe($secret, 'pm_card_chargeDeclined');
    $body = Target::json($confirmed);

    // Stripe answers 402 with an error rather than an intent. What matters here
    // is only that it refused, not the shape of how it said so.
    expect($confirmed->getStatusCode())->toBe(402)
        ->and($body['error']['code'] ?? null)->toBe('card_declined');

    Target::say('Stripe declined the card, as asked. Checking the order is still payable.');

    // Long enough for a webhook about the failure to have arrived and done
    // whatever it was going to do.
    sleep(10);

    expect(Target::json($client->get('api/v1/me', ['headers' => $asCustomer]))['has_viewing_room_access'])
        ->toBeFalse('A declined card granted the pass. Nothing else in this walk matters until that is fixed.');

    // Still payable, which is the whole of the no-expiry decision observed from
    // outside: a fresh secret comes back for the same order.
    $retry = $client->post('api/v1/orders/'.$order['pay_token'].'/pay', [
        'headers' => $asCustomer,
        'json' => ['method' => 'stripe'],
    ]);

    expect($retry->getStatusCode())->toBe(200)
        ->and(Target::json($retry)['type'])->toBe('client_secret')
        ->and(Target::json($retry)['client_secret'])->not->toBe($secret);

    $second = confirmAtStripe((string) Target::json($retry)['client_secret'], 'pm_card_visa');

    expect(Target::json($second)['status'] ?? null)->toBe(
        'succeeded',
        'A customer whose first card was declined could not pay with a second one.'
    );

    Target::say('The declined order was paid on a second attempt.');
});

/**
 * **A card requiring 3D Secure must genuinely trigger it.** An intent created
 * without `automatic_payment_methods` would quietly succeed here instead, and
 * that is a payment taken in a way the issuer never authorised, which is a
 * chargeback nobody can contest.
 *
 * The challenge itself is an iframe a person completes, so this walk stops at
 * Stripe saying the card needs one. Finishing it is the frontend partner's, in
 * a browser.
 */
it('asks for authentication where the card requires it', function (): void {
    $client = Target::client();
    ['account' => $account, 'secret' => $secret] = orderReadyToPay('threeds');
    $asCustomer = Target::as($account);

    $confirmed = confirmAtStripe($secret, 'pm_card_authenticationRequired');
    $body = Target::json($confirmed);

    expect($confirmed->getStatusCode())->toBe(200);

    expect($body['status'] ?? null)->toBe(
        'requires_action',
        'A card that requires 3D Secure was not challenged. Stripe said "'.($body['status'] ?? 'nothing')
        .'". An intent that skips authentication takes payments the issuer never approved.'
    );

    expect($body['next_action'] ?? null)->not->toBeNull();

    Target::say('3D Secure was demanded, as it must be. The challenge itself needs a browser.');

    sleep(10);

    // Unauthenticated is unpaid. Nothing may be delivered against an intent
    // still waiting on the customer.
    expect(Target::json($client->get('api/v1/me', ['headers' => $asCustomer]))['has_viewing_room_access'])
        ->toBeFalse('A payment still waiting on 3D Secure granted the pass.');
});

/**
 * From outside, a token nobody holds and a token belonging to somebody else are
 * the same thing, and they have to stay the same thing. Pay tokens are the
 * whole authority a guest has over an order.
 */
it('answers 404 for a pay token nobody holds', function (): void {
    $response = Target::client()->post('api/v1/orders/'.str_repeat('a', 48).'/pay', [
        'json' => ['method' => 'stripe'],
    ]);

    Target::assertNotThrottled($response, 'orders-pay');

    expect($response->getStatusCode())->toBe(404);
});
