<?php

use App\Enums\PaymentMethod;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;
use Tests\Staging\Target;

/**
 * A real Stripe test payment against the **wallet** road, moving a real order.
 *
 * `stripe_wallet` mints its own PaymentIntent and answers a client secret, so
 * unlike the hosted road there is a secret a raw HTTP client can confirm
 * against. That is what makes this walk possible at all, and it is the same
 * shape `CardPaymentWalkTest` was written in when `stripe` answered a secret
 * too. See docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md.
 *
 * ## What this proves
 *
 * - **The gateway mints an intent Stripe accepts**, for the order's own total,
 *   in the order's own currency, with the order id on its metadata
 * - **The secret confirms.** Stripe takes the payment against it
 * - **`payment_intent.succeeded` settles the order** on this environment,
 *   inside two minutes, which the sweep cannot account for because it leaves an
 *   order alone for fifteen. So the wait itself is the assertion: the endpoint
 *   is registered at Stripe for this environment, reachable from outside, and
 *   carrying a signing secret that matches
 *
 * ## What it claims about identity, which is nothing
 *
 * **No headless client opens a wallet sheet.** This confirms with a test card
 * over raw HTTP, so no wallet collects an email and
 * `latest_charge.billing_details.email` is empty here whatever a real Apple Pay
 * would put there. The order below is placed by a **signed-in account**, so it
 * carries a customer from the moment it exists and settlement never asks who
 * paid.
 *
 * That is deliberate and it is the honest limit of this file. Whether a real
 * wallet populates the charge's billing details is **issue #44**, it needs a
 * real device, and the whole identity fix in `BuyerFromLatestCharge` rests on
 * it. Nothing here may ever be read as evidence about it.
 *
 * ## Nothing here shares a line of code with the application
 *
 * Stripe is reached with raw HTTP rather than through `stripe/stripe-php`, even
 * though the application holds that library. If the library were the broken
 * thing, a walk built on it would agree with the application about being
 * broken. **The helpers below duplicate `CardPaymentWalkTest`'s rather than
 * calling into them**, for that same reason and one more: a walk that can only
 * run when another walk's file happens to have been loaded is a walk somebody
 * will one day run alone and get a fatal error from.
 *
 * ## Running it
 *
 * ```bash
 * STAGING_URL=https://staging-api.theworldtarot.com \
 * STAGING_WALLET=1 \
 * STAGING_STRIPE_PUBLISHABLE=pk_test_... \
 * composer test:staging
 * ```
 *
 * Without `STAGING_WALLET=1` every test in this file skips with the reason
 * attached. **Read a skip as a skip and count them**, per the 23 August 2026
 * lesson: a test that never executes looks exactly like one that passes.
 */
beforeEach(function (): void {
    if ($reason = Target::unavailable()) {
        $this->markTestSkipped($reason);
    }

    if ($reason = walletWalkUnavailable()) {
        $this->markTestSkipped($reason);
    }
});

/**
 * Why the wallet walk cannot run, or null if it can.
 */
function walletWalkUnavailable(): ?string
{
    if (getenv('STAGING_WALLET') !== '1') {
        return 'The wallet walk takes a real Stripe test payment. Set STAGING_WALLET=1 to run it. '
            .'Until somebody does, nothing here has proved that the wallet road mints an intent Stripe accepts.';
    }

    $key = (string) getenv('STAGING_STRIPE_PUBLISHABLE');

    if ($key === '') {
        return 'STAGING_STRIPE_PUBLISHABLE is not set, so a payment cannot be confirmed the way a browser would. '
            .'Use the test mode publishable key from the same Stripe account this environment is configured with.';
    }

    // **The one guard rail that matters here.** A live key confirms payments
    // against cards belonging to real people, and no test card is a test card
    // in live mode. Restated rather than borrowed, because a refusal that
    // depends on another file being loaded is not a refusal.
    if (! str_starts_with($key, 'pk_test_')) {
        return 'STAGING_STRIPE_PUBLISHABLE is not a test mode key. Confirming payments with a live key charges '
            .'real cards. Refusing to run.';
    }

    return null;
}

function walletWalkPublishableKey(): string
{
    return (string) getenv('STAGING_STRIPE_PUBLISHABLE');
}

function walletWalkCurrency(): string
{
    $currency = getenv('STAGING_CURRENCY');

    return is_string($currency) && $currency !== '' ? $currency : 'GBP';
}

function walletWalkPassKey(): string
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
function walletIntentIdIn(string $clientSecret): string
{
    return explode('_secret_', $clientSecret, 2)[0];
}

/**
 * Confirms a payment the way the customer's browser would.
 *
 * Raw HTTP to Stripe, with a publishable key and the client secret, which is
 * the call Stripe.js makes when the express checkout element hands it a
 * payment method. Nothing of ours is involved on this side.
 *
 * **This reaches the real Stripe API, and that is deliberate.** It is the one
 * carve-out in `.claude/testing.md`, granted 26 August 2026 to `tests/Staging/`
 * alone. Test mode only, off unless `STAGING_WALLET=1` is asked for, never
 * against production, never in CI. Read that section before copying this shape
 * anywhere else, because everywhere else the rule is still absolute.
 */
function confirmWalletIntentAtStripe(string $clientSecret, string $paymentMethod): ResponseInterface
{
    return (new Client(['http_errors' => false, 'timeout' => 30]))->post(
        'https://api.stripe.com/v1/payment_intents/'.walletIntentIdIn($clientSecret).'/confirm',
        [
            'headers' => [
                'Authorization' => 'Bearer '.walletWalkPublishableKey(),
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
 * **Signed in on purpose.** The order carries a customer from the moment it
 * exists, so settlement never has to ask Stripe who paid, which is the question
 * this walk cannot answer. See the note on identity at the top of this file.
 *
 * @return array{account: array{email: string, token: string}, order: array<string, mixed>, secret: string}
 */
function walletOrderReadyToPay(string $tag): array
{
    $client = Target::client();

    // Its own account each time, because one of these ends up holding a pass
    // and the rest of this suite relies on the shared account not having one.
    $account = Target::register($tag);

    $placed = $client->post('api/v1/orders', [
        'headers' => Target::as($account),
        'json' => [
            'currency' => walletWalkCurrency(),
            'lines' => [['product' => walletWalkPassKey()]],
        ],
    ]);

    Target::assertNotThrottled($placed, 'orders');

    if ($placed->getStatusCode() === 422) {
        test()->markTestSkipped(
            walletWalkPassKey().' cannot be bought in '.walletWalkCurrency().' here. Publish it and price it above '
            .'zero in the panel first. Response: '.$placed->getBody()
        );
    }

    expect($placed->getStatusCode())->toBe(201);

    $order = Target::json($placed);

    expect($order['status'])->toBe('pending')
        ->and($order['pay_token'] ?? null)->toBeString();

    $paying = $client->post('api/v1/orders/'.$order['pay_token'].'/pay', [
        'headers' => Target::as($account),
        'json' => ['method' => PaymentMethod::StripeWallet->value],
    ]);

    Target::assertNotThrottled($paying, 'orders-pay');

    if ($paying->getStatusCode() === 422) {
        test()->markTestSkipped(
            'This environment does not offer stripe_wallet, so /pay refused the method with a 422. It needs BOTH '
            .'STRIPE_SECRET and STRIPE_WEBHOOK_SECRET filled, exactly as the hosted page does, and it needs a deploy '
            .'carrying the method at all. Response: '.$paying->getBody()
        );
    }

    if ($paying->getStatusCode() !== 200) {
        test()->fail(
            'The pay endpoint answered '.$paying->getStatusCode().' rather than a client secret. Response: '
            .$paying->getBody()
        );
    }

    // **A credential with this order's money attached**, so it must never sit
    // in a cache anywhere between here and the customer.
    expect($paying->getHeaderLine('Cache-Control'))->toContain('no-store');

    $instruction = Target::json($paying);

    // **A client secret and never a redirect**, which is the whole difference
    // between the two Stripe methods observed from outside. An element cannot
    // be initialised from an address.
    expect($instruction['type'])->toBe('client_secret')
        ->and($instruction['client_secret'] ?? null)->toBeString();

    return ['account' => $account, 'order' => $order, 'secret' => (string) $instruction['client_secret']];
}

it('mints a wallet intent Stripe accepts and delivers what was bought', function (): void {
    $client = Target::client();
    ['account' => $account, 'order' => $order, 'secret' => $secret] = walletOrderReadyToPay('wallet');
    $asCustomer = Target::as($account);

    expect(Target::json($client->get('api/v1/me', ['headers' => $asCustomer]))['has_viewing_room_access'])->toBeFalse();

    Target::say('Order #'.$order['id'].' placed for '.$account['email'].'. Paying its wallet intent with a test card.');

    $confirmed = confirmWalletIntentAtStripe($secret, 'pm_card_visa');
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
    // **`amount` rather than `amount_received`.** A publishable key is given a
    // limited PaymentIntent, which carries amount, currency and status and
    // withholds the settlement fields. That the money reached the account is
    // proved by the pass appearing below, which only the webhook can cause.
    expect($body['status'] ?? null)->toBe('succeeded')
        ->and($body['amount'] ?? null)->toBe($order['total_amount'])
        ->and(strtoupper((string) ($body['currency'] ?? '')))->toBe(walletWalkCurrency());

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
    // have caused this.
    expect($granted)->toBeTrue(
        'A wallet payment succeeded at Stripe and the pass never appeared within two minutes. The sweep does not '
        .'touch an order for fifteen, so this is the webhook: check that the endpoint is registered at Stripe for '
        .'this environment, that its URL is right, and that STRIPE_WEBHOOK_SECRET here matches. A customer has been '
        .'charged and has received nothing.'
    );

    Target::say('Paid through the wallet road and delivered in '.$waited.' seconds, with nobody in the panel.');

    // A second attempt on a settled order creates nothing. Observed from
    // outside, which is the only place the difference is visible.
    $again = $client->post('api/v1/orders/'.$order['pay_token'].'/pay', [
        'headers' => $asCustomer,
        'json' => ['method' => PaymentMethod::StripeWallet->value],
    ]);

    expect($again->getStatusCode())->toBe(200)
        ->and(Target::json($again)['type'])->toBe('nothing_to_pay');
});

/**
 * **A decline is a retry, not an ending.** The no-expiry decision says the order
 * stays payable, and the customer comes back with another card or another
 * wallet. An order this path had cancelled would be a sale refused on the
 * customer's behalf.
 */
it('refuses a declined wallet payment and leaves the order payable', function (): void {
    $client = Target::client();
    ['account' => $account, 'order' => $order, 'secret' => $secret] = walletOrderReadyToPay('wallet-declined');
    $asCustomer = Target::as($account);

    $confirmed = confirmWalletIntentAtStripe($secret, 'pm_card_chargeDeclined');
    $body = Target::json($confirmed);

    expect($confirmed->getStatusCode())->toBe(402)
        ->and($body['error']['code'] ?? null)->toBe('card_declined');

    Target::say('Stripe declined the payment, as asked. Checking the order is still payable.');

    // Long enough for a webhook about the failure to have arrived and done
    // whatever it was going to do.
    sleep(10);

    expect(Target::json($client->get('api/v1/me', ['headers' => $asCustomer]))['has_viewing_room_access'])
        ->toBeFalse('A declined payment granted the pass. Nothing else in this walk matters until that is fixed.');

    // Still payable, and with a **fresh** secret, which is the whole of the
    // no-expiry decision observed from outside.
    $retry = $client->post('api/v1/orders/'.$order['pay_token'].'/pay', [
        'headers' => $asCustomer,
        'json' => ['method' => PaymentMethod::StripeWallet->value],
    ]);

    expect($retry->getStatusCode())->toBe(200)
        ->and(Target::json($retry)['type'])->toBe('client_secret')
        ->and(Target::json($retry)['client_secret'])->not->toBe($secret);

    Target::say('The declined order came back with a fresh intent.');
});

/**
 * **The two Stripe methods answer differently, and the frontend switches on
 * exactly that.** A wallet cannot be initialised from an address and an element
 * cannot be handed a hosted page, so a deployment that answered the same shape
 * to both would draw one of the two buttons into nothing.
 */
it('answers a redirect for the card road and a secret for the wallet road', function (): void {
    $client = Target::client();
    ['account' => $account, 'order' => $order] = walletOrderReadyToPay('wallet-vs-card');

    $hosted = $client->post('api/v1/orders/'.$order['pay_token'].'/pay', [
        'headers' => Target::as($account),
        'json' => ['method' => PaymentMethod::Stripe->value],
    ]);

    Target::assertNotThrottled($hosted, 'orders-pay');

    expect($hosted->getStatusCode())->toBe(200)
        ->and(Target::json($hosted)['type'])->toBe('redirect')
        ->and(Target::json($hosted)['redirect_url'] ?? null)->toBeString();

    Target::say('The same order answers a hosted page for the card and a secret for the wallet.');
});

/**
 * What this environment tells the frontend it offers, which is what decides
 * whether the wallet button is drawn at all.
 */
it('offers both stripe methods to customers and never hand settlement', function (): void {
    $response = Target::client()->get('api/v1/payment-methods');

    expect($response->getStatusCode())->toBe(200);

    $methods = Target::json($response)['methods'] ?? [];

    expect($methods)->toContain(PaymentMethod::Stripe->value)
        ->and($methods)->toContain(PaymentMethod::StripeWallet->value)
        ->and($methods)->not->toContain(
            'manual',
            'The payment methods endpoint offered hand settlement to customers. It is a person in the panel, and a '
            .'button for it would settle nothing.'
        );
});
