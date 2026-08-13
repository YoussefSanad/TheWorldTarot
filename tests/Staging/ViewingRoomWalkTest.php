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
 * never been a way to make one. Somebody buying a pass and an order being
 * settled by hand is that way.
 *
 * ## The denials run on their own. The purchase needs you.
 *
 * Nothing collects money yet, by design, so a person has to open the admin
 * panel and mark the order paid. That is not a limitation of this suite, it is
 * the product: `ManualSettlement` is the only payment gateway there is.
 *
 * Run the full walk with:
 *
 * ```bash
 * STAGING_URL=... STAGING_INTERACTIVE=1 composer test:staging
 * ```
 *
 * It will place an order, print its number, and wait for you to settle it while
 * watching `/api/v1/me` for the pass to appear.
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
});

it('answers 404 for a card with nothing to watch', function (): void {
    $cards = Target::json(Target::client()->get('api/v1/'.walkLocale().'/cards'));

    $without = collect($cards)->first(fn (array $card): bool => ($card['in_viewing_room'] ?? false) === false);

    if ($without === null) {
        $this->markTestSkipped('Every card here has a film, so there is no empty case to check.');
    }

    $response = Target::client()->get('api/v1/'.walkLocale().'/cards/'.$without['id'].'/video', [
        'headers' => Target::as(Target::sharedAccount()),
    ]);

    expect($response->getStatusCode())->toBe(404);
});

it('sells a pass, grants it when the money is recorded, and plays the film', function (): void {
    $card = cardWithFilm();

    if ($card === null) {
        $this->markTestSkipped('No card on this environment has a finished film, so there is nothing to play.');
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

    if ($placed->getStatusCode() === 422) {
        $this->markTestSkipped(
            $passKey.' cannot be bought in '.$currency.' here. Publish it and price it above zero in the panel first. '
            .'Response: '.$placed->getBody()
        );
    }

    expect($placed->getStatusCode())->toBe(201);

    $order = Target::json($placed);

    expect($order['status'])->toBe('pending');

    Target::say(str_repeat('=', 72));
    Target::say('Order #'.$order['id'].' is waiting, for '.$email);
    Target::say('Open '.Target::url('/admin').', go to Orders, and use "Mark as paid".');
    Target::say('This is watching for the pass to appear. It gives up after five minutes.');
    Target::say(str_repeat('=', 72));

    $granted = false;
    $deadline = time() + 300;

    while (time() < $deadline) {
        sleep(5);

        $me = Target::json($client->get('api/v1/me', ['headers' => $asCustomer]));

        if (($me['has_viewing_room_access'] ?? false) === true) {
            $granted = true;

            break;
        }
    }

    expect($granted)->toBeTrue('The pass never appeared. Either the order was not settled, or settling it did not grant access.');

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
    fn (): bool => getenv('STAGING_INTERACTIVE') !== '1',
    'Needs somebody to mark an order paid in the admin panel. Set STAGING_INTERACTIVE=1 to run it.'
);
