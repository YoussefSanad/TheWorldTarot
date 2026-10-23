<?php

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Tests\Staging\Target;

/**
 * The walk this whole milestone was sequenced to make possible.
 *
 * `docs/CHECKPOINT.md` has carried "the Viewing Room path is unwalked on
 * staging" since M4, because walking it needed a pass holder and there had
 * never been a way to make one. ~~Somebody buying a pass and an order being
 * settled by hand is that way.~~ Struck through 22 October 2026: somebody
 * buying a pass and paying for it through Stripe is that way now.
 *
 * ~~## The denials run on their own. The purchase needs you.~~
 *
 * ~~Nothing collects money yet, by design, so a person has to open the admin
 * panel and mark the order paid. That is not a limitation of this suite, it is
 * the product: `ManualSettlement` is the only payment gateway there is.~~
 *
 * ~~Run the full walk with `STAGING_URL=... STAGING_INTERACTIVE=1 composer
 * test:staging`. It will place an order, print its number, and wait for you to
 * settle it.~~
 *
 * **Struck through 22 October 2026.** Hand settlement is not the product, it is
 * a panel action for an order nobody paid through the site. Stripe has taken
 * payments on staging since 26 August 2026, so this walk now buys its pass the
 * way a customer does and nobody opens the panel.
 *
 * ## The denials run on their own. The purchase takes a Stripe test payment.
 *
 * It places an order, pays it with `stripe_wallet`, confirms the client secret
 * at Stripe with `pm_card_visa` and the `pk_test_` key (a `pk_live_` key is
 * refused), then waits for `/api/v1/me` to show the pass. The helpers are
 * restated from `WalletPaymentWalkTest` rather than shared, for the reason
 * given in that file: a walk that only runs when another file was loaded is a
 * fatal error waiting for somebody to run it alone.
 *
 * ```bash
 * STAGING_URL=... STAGING_WALLET=1 STAGING_STRIPE_PUBLISHABLE=pk_test_... \
 * composer test:staging --filter=ViewingRoomWalk
 * ```
 *
 * Gated on `STAGING_WALLET=1`, the same switch as the wallet walk, because it
 * moves the same Stripe test money. `STAGING_INTERACTIVE` no longer gates it.
 *
 * ## The part that has to be done properly
 *
 * A 200 from the video endpoint proves nothing about playback. **A token scoped
 * to the manifest instead of the directory returns 200 for `playlist.m3u8` and
 * 403 for every rendition and segment it points at**, which is indistinguishable
 * from working unless you follow it down. That is exactly what M4 shipped, and
 * what stayed green in the suite for five days. So this walks the manifest, then
 * a rendition, then a real segment, the way a player does.
 */
beforeEach(function (): void {
    if ($reason = Target::unavailable()) {
        $this->markTestSkipped($reason);
    }
});

function walkLocale(): string
{
    $locale = getenv('STAGING_LOCALE');

    return is_string($locale) && $locale !== '' ? $locale : 'en';
}

/**
 * A card with a finished film, discovered rather than assumed.
 *
 * @return array<string, mixed>|null
 */
function cardWithFilm(): ?array
{
    $cards = Target::json(Target::client()->get('api/v1/'.walkLocale().'/cards'));

    foreach ($cards as $card) {
        if (($card['in_viewing_room'] ?? false) === true) {
            return $card;
        }
    }

    return null;
}

/**
 * Follows a manifest down to something a player would actually stream.
 *
 * @return array<int, string> The first rendition and the first segment it lists
 */
function childrenOf(string $manifestUrl, string $body): array
{
    $children = [];
    $base = new Uri($manifestUrl);

    foreach (preg_split('/\R/', $body) ?: [] as $line) {
        $line = trim($line);

        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }

        $children[] = (string) UriResolver::resolve($base, new Uri($line));

        break;
    }

    return $children;
}

it('refuses a film to somebody who has not paid for one', function (): void {
    $card = cardWithFilm();

    if ($card === null) {
        $this->markTestSkipped('No card on this environment has a finished film, so there is no Viewing Room to walk yet.');
    }

    $client = Target::client();
    $path = 'api/v1/'.walkLocale().'/cards/'.$card['id'].'/video';

    $anonymous = $client->get($path);

    // 401 for nobody at all.
    expect($anonymous->getStatusCode())->toBe(401);

    $signedIn = $client->get($path, [
        'headers' => Target::as(Target::sharedAccount()),
    ]);

    // **403 and not 404**, deliberately. The Viewing Room is advertised, so
    // hiding it would only confuse somebody who paid and is signed in as the
    // wrong account.
    expect($signedIn->getStatusCode())->toBe(403);

    // **And the identical 403 for a card with nothing to watch**, which is the
    // half that matters. The pass is asked about before the card is ever looked
    // up, so every id looks the same from out here. If this one answered 404
    // while the one above answered 403, anybody with an account could map the
    // Viewing Room one id at a time and learn exactly which films exist.
    $cards = Target::json($client->get('api/v1/'.walkLocale().'/cards'));
    $without = collect($cards)->first(fn (array $c): bool => ($c['in_viewing_room'] ?? false) === false);

    if ($without !== null) {
        expect($client->get('api/v1/'.walkLocale().'/cards/'.$without['id'].'/video', [
            'headers' => Target::as(Target::sharedAccount()),
        ])->getStatusCode())->toBe(403);
    }
});

it('sells a pass, grants it when the money is recorded, and plays the film', function (): void {
    $card = cardWithFilm();

    if ($card === null) {
        $this->markTestSkipped('No card on this environment has a finished film, so there is nothing to play.');
    }

    $publishableKey = (string) getenv('STAGING_STRIPE_PUBLISHABLE');

    if ($publishableKey === '') {
        $this->markTestSkipped('STAGING_STRIPE_PUBLISHABLE is not set, so the pass cannot be paid for the way a browser would.');
    }

    // A live key confirms against real cards. Restated, not borrowed.
    if (! str_starts_with($publishableKey, 'pk_test_')) {
        $this->markTestSkipped('STAGING_STRIPE_PUBLISHABLE is not a test mode key. Refusing to run.');
    }

    $client = Target::client();

    // Its own account, because this one ends up holding a pass and every other
    // test in this suite expects not to.
    $account = Target::register('pass');
    $email = $account['email'];
    $asCustomer = Target::as($account);

    expect(Target::json($client->get('api/v1/me', ['headers' => $asCustomer]))['has_viewing_room_access'])->toBeFalse();

    $passKey = getenv('STAGING_PASS_KEY') ?: 'viewing-room-pass';
    $currency = getenv('STAGING_CURRENCY') ?: 'GBP';

    $placed = $client->post('api/v1/orders', [
        'headers' => $asCustomer,
        'json' => [
            'currency' => $currency,
            'lines' => [['product' => $passKey]],
        ],
    ]);

    Target::assertNotThrottled($placed, 'orders');

    if ($placed->getStatusCode() === 422) {
        $this->markTestSkipped(
            $passKey.' cannot be bought in '.$currency.' here. Publish it and price it above zero in the panel first. '
            .'Response: '.$placed->getBody()
        );
    }

    expect($placed->getStatusCode())->toBe(201);

    $order = Target::json($placed);

    expect($order['status'])->toBe('pending');

    expect($order['pay_token'] ?? null)->toBeString();

    $paying = $client->post('api/v1/orders/'.$order['pay_token'].'/pay', [
        'headers' => $asCustomer,
        'json' => ['method' => 'stripe_wallet'],
    ]);

    Target::assertNotThrottled($paying, 'orders-pay');

    if ($paying->getStatusCode() === 422) {
        $this->markTestSkipped(
            'This environment does not offer stripe_wallet. It needs STRIPE_SECRET and STRIPE_WEBHOOK_SECRET both '
            .'filled. Response: '.$paying->getBody()
        );
    }

    expect($paying->getStatusCode())->toBe(200);

    $instruction = Target::json($paying);

    expect($instruction['type'])->toBe('client_secret')
        ->and($instruction['client_secret'] ?? null)->toBeString();

    $secret = (string) $instruction['client_secret'];

    Target::say('Order #'.$order['id'].' placed for '.$email.'. Paying it with a Stripe test card.');

    $confirmed = (new Client(['http_errors' => false, 'timeout' => 30]))->post(
        'https://api.stripe.com/v1/payment_intents/'.explode('_secret_', $secret, 2)[0].'/confirm',
        [
            'headers' => [
                'Authorization' => 'Bearer '.$publishableKey,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => [
                'client_secret' => $secret,
                'payment_method' => 'pm_card_visa',
                'return_url' => Target::origin().'/',
            ],
        ],
    );

    if ($confirmed->getStatusCode() !== 200) {
        $this->fail('Stripe refused the confirmation with '.$confirmed->getStatusCode().': '.$confirmed->getBody());
    }

    $body = Target::json($confirmed);

    // `amount` and not `amount_received`: a publishable key gets a limited
    // PaymentIntent that withholds the settlement fields.
    expect($body['status'] ?? null)->toBe('succeeded')
        ->and($body['amount'] ?? null)->toBe($order['total_amount'])
        ->and(strtoupper((string) ($body['currency'] ?? '')))->toBe(strtoupper($currency));

    $granted = false;
    $deadline = time() + 120;

    while (time() < $deadline) {
        sleep(5);

        $me = Target::json($client->get('api/v1/me', ['headers' => $asCustomer]));

        if (($me['has_viewing_room_access'] ?? false) === true) {
            $granted = true;

            break;
        }
    }

    expect($granted)->toBeTrue('A Stripe test payment succeeded and the pass never appeared within two minutes. The sweep leaves an order alone for fifteen, so this is the webhook, or settling did not grant access.');

    // **404 now, where it could not be checked before.** A card with nothing to
    // watch answers 404 to somebody holding a pass and 403 to everybody else,
    // and only an account that has paid can tell the two apart. This lived in a
    // test of its own until 23 August 2026, using the shared account that
    // deliberately never holds a pass, so it asserted a 404 the design must
    // never give and failed the first time staging had both a card with a film
    // and a card without one.
    $cardsHere = Target::json($client->get('api/v1/'.walkLocale().'/cards'));
    $nothingToWatch = collect($cardsHere)->first(fn (array $c): bool => ($c['in_viewing_room'] ?? false) === false);

    if ($nothingToWatch !== null) {
        expect($client->get('api/v1/'.walkLocale().'/cards/'.$nothingToWatch['id'].'/video', [
            'headers' => $asCustomer,
        ])->getStatusCode())->toBe(404);
    }

    Target::say('Access granted. Now proving the film actually plays.');

    $response = $client->get('api/v1/'.walkLocale().'/cards/'.$card['id'].'/video', [
        'headers' => $asCustomer,
    ]);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getHeaderLine('Cache-Control'))->toContain('no-store');

    $video = Target::json($response);

    expect($video)->toHaveKeys(['card_id', 'url', 'expires_at']);

    // Thirty minutes, since 7 August 2026. The window is the only thing
    // limiting a copied link now that the address is out of the signature.
    $life = strtotime((string) $video['expires_at']) - time();

    expect($life)->toBeGreaterThan(0)->toBeLessThanOrEqual(35 * 60);

    // The CDN refuses a request carrying no referrer, which is why a playback
    // URL cannot be debugged by pasting it into a browser tab.
    $cdn = new Client([
        'http_errors' => false,
        'timeout' => 30,
        'headers' => ['Referer' => Target::origin().'/'],
    ]);

    $manifest = $cdn->get($video['url']);
    $manifestBody = (string) $manifest->getBody();

    expect($manifest->getStatusCode())->toBe(200)
        ->and($manifestBody)->toStartWith('#EXTM3U');

    $renditions = childrenOf($video['url'], $manifestBody);

    expect($renditions)->not->toBeEmpty('The master manifest listed no renditions, so there is nothing to play.');

    $rendition = $cdn->get($renditions[0]);
    $renditionBody = (string) $rendition->getBody();

    // **If this 403s while the manifest was 200, the token is scoped to the
    // file rather than the directory.** That is the M4 bug exactly, and from
    // curl it is otherwise indistinguishable from success.
    expect($rendition->getStatusCode())->toBe(200)
        ->and($renditionBody)->toStartWith('#EXTM3U');

    $segments = childrenOf($renditions[0], $renditionBody);

    expect($segments)->not->toBeEmpty('The rendition listed no segments.');

    $segment = $cdn->get($segments[0]);

    // The only check on this list that proves playback rather than implying it.
    expect($segment->getStatusCode())->toBe(200)
        ->and(strlen((string) $segment->getBody()))->toBeGreaterThan(0);

    Target::say('The film played: manifest, rendition and segment.');

    // Signed rather than merely long. One character is enough.
    $tampered = substr_replace($video['url'], 'X', strpos($video['url'], 'bcdn_token=') + 11, 1);

    expect($cdn->get($tampered)->getStatusCode())->toBe(403);

    Target::say('A tampered token was refused, so the URL is genuinely signed.');
})->skip(
    fn (): bool => getenv('STAGING_WALLET') !== '1',
    'Takes a real Stripe test payment. Set STAGING_WALLET=1 and STAGING_STRIPE_PUBLISHABLE=pk_test_... to run it.'
);
