<?php

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use Psr\Http\Message\ResponseInterface;
use Tests\Staging\Target;

/**
 * A present bought, its code received, and the reading asked for by somebody
 * else entirely, against a deployed environment.
 *
 * B10, issue #76, and the last unit of the gifting epic. The two payment walks
 * beside this one prove a customer can hand over money and receive what they
 * bought. **Gifting is the case where those two things happen to two different
 * people, months apart**, and nothing in this repository had ever walked the
 * gap between them.
 *
 * ## What is different about this walk, and it is one thing
 *
 * **The buyer never receives the code.** It is minted at settlement and mailed
 * to the recipient, and `MarkOrderPaid` says in as many words that these are
 * two messages carrying different things to different people out of one
 * payment. So there is no response anywhere on the wire that this walk can read
 * a code out of: `POST /orders` says outright that the code never appears in
 * it, and `GiftLookupResource` answers only to somebody who already holds one.
 *
 * That is a property of the design and not a gap in it, so this walk asks a
 * person, exactly as `ViewingRoomWalkTest` asks one to settle an order. Set
 * `STAGING_EMAIL` to your own address, watch the recipient's mail arrive, and
 * type the code in. **The waiting is itself the assertion**: a code that never
 * arrives means either the payment did not settle here or the gift mail went
 * nowhere, and both are the failure this unit exists to find.
 *
 * ## What it proves
 *
 * - **The catalogue says which readings may be given away.** `is_giftable`
 *   answers on this environment, and a gift on a line that says false is
 *   refused rather than quietly dropped, which would charge somebody for a
 *   present nobody was ever sent
 * - **A gift line is accepted, echoed back, and carries no code**
 * - **Settlement mints one.** The code exists, which means `MarkOrderPaid` ran
 *   on this environment against a real payment
 * - **Looking a code up does not spend it.** Asked twice before anything is
 *   redeemed, because a link prefetcher in the recipient's inbox gets exactly
 *   one go at this and there are no refunds
 * - **Redemption happens in a second session as a different person.** A fresh
 *   client, no cookie jar of the buyer's, no bearer token, and a querent
 *   address that is deliberately not the address the gift was sent to
 * - **The clock starts on the day of redemption.** `asked_at` comes back from
 *   the redemption itself, which is the moment `AskForReading` tells Jennifer
 * - **And it starts once.** A second submit of the same code is a 409, so there
 *   is no second reading and no second message
 *
 * ## What it cannot prove, and who has to
 *
 * **That Jennifer was told, which is a mailbox.** No assertion out here can see
 * `A reading is waiting to be written` arrive, and the README has said since
 * this suite was written that a mailbox is the one thing it cannot reach. What
 * this walk does instead is print the wall clock instant of the settlement and
 * the wall clock instant of the redemption, so checking the inbox is comparing
 * two timestamps rather than making a judgement. **The message must carry the
 * second one and there must be exactly one of it.**
 *
 * **Whether the webhook settled it, rather than the sweep.** This walk does not
 * try: `WalletPaymentWalkTest` proves the endpoint is registered and reachable
 * on this environment by watching a pass appear inside two minutes, and proving
 * it twice buys nothing. What is asserted here is that a code exists at all.
 * The elapsed time is reported for whatever it is worth, and it is worth
 * something only if the tester was prompt.
 *
 * ## Running it
 *
 * ```bash
 * STAGING_URL=https://staging-api.theworldtarot.com \
 * STAGING_EMAIL=you@example.com \
 * STAGING_GIFT=1 \
 * STAGING_STRIPE_PUBLISHABLE=pk_test_... \
 * composer test:staging
 * ```
 *
 * Without `STAGING_GIFT=1` every test in this file skips with the reason
 * attached. **Read a skip as a skip and count them**, per the 23 August 2026
 * lesson recorded in the README: a test that never executes looks exactly like
 * one that passes, and two of the three failures in the card walk's first run
 * were tests that had been skipping since the day before.
 *
 * **Three of the four tests here move no money** and need no Stripe key. They
 * run on `STAGING_GIFT=1` alone, so an environment can be asked whether it
 * carries gifting at all without taking a payment to find out.
 *
 * ## Nothing here shares a line of code with the application
 *
 * No enum, no constant, no class. The method name, the product key and the
 * shape of a code are all written out as the literals a customer's browser
 * would send, for the reason `Target` gives at length: a walk that imports our
 * own names agrees with a rename that a deployed environment has never heard
 * of, and then passes.
 */
beforeEach(function (): void {
    if ($reason = Target::unavailable()) {
        $this->markTestSkipped($reason);
    }

    if ($reason = giftWalkUnavailable()) {
        $this->markTestSkipped($reason);
    }
});

/**
 * Why the gift walk cannot run at all, or null if it can.
 *
 * **Separate from the Stripe half below**, because most of this file needs no
 * payment. Folding the two together would mean an environment with no
 * publishable key to hand skipped the three checks that would have told it
 * gifting is not deployed.
 */
function giftWalkUnavailable(): ?string
{
    if (getenv('STAGING_GIFT') !== '1') {
        return 'The gift walk needs a person to read a code out of an inbox, and its last test takes a real Stripe '
            .'test payment. Set STAGING_GIFT=1 to run it. Until somebody does, nothing here has proved that a '
            .'present can be bought and redeemed on any environment.';
    }

    return null;
}

/**
 * Why the paying half cannot run, or null if it can.
 */
function giftWalkCannotPay(): ?string
{
    $key = (string) getenv('STAGING_STRIPE_PUBLISHABLE');

    if ($key === '') {
        return 'STAGING_STRIPE_PUBLISHABLE is not set, so a payment cannot be confirmed the way a browser would, '
            .'and no payment means no code is ever minted. Use the test mode publishable key from the same Stripe '
            .'account this environment is configured with.';
    }

    // **The one guard rail that matters here.** A live key confirms payments
    // against cards belonging to real people, and no test card is a test card
    // in live mode. Restated rather than borrowed from the walks beside this
    // one, because a refusal that depends on another file being loaded is not a
    // refusal.
    if (! str_starts_with($key, 'pk_test_')) {
        return 'STAGING_STRIPE_PUBLISHABLE is not a test mode key. Confirming payments with a live key charges '
            .'real cards. Refusing to run.';
    }

    return null;
}

function giftWalkPublishableKey(): string
{
    return (string) getenv('STAGING_STRIPE_PUBLISHABLE');
}

function giftWalkLocale(): string
{
    $locale = getenv('STAGING_LOCALE');

    return is_string($locale) && $locale !== '' ? $locale : 'en';
}

function giftWalkCurrency(): string
{
    $currency = getenv('STAGING_CURRENCY');

    return is_string($currency) && $currency !== '' ? $currency : 'GBP';
}

/**
 * The reading this walk gives away.
 *
 * `month-ahead` because it is the one giftable reading with a page on the
 * website, so a code minted by this walk is one somebody can actually follow
 * through to a rendered `/redeem/`. `STAGING_READING_KEY` is deliberately not
 * reused: it defaults to `one-card`, which takes a question and cannot be
 * gifted, and is the product the two fields are independent on.
 */
function giftWalkGiftKey(): string
{
    $key = getenv('STAGING_GIFT_KEY');

    return is_string($key) && $key !== '' ? $key : 'month-ahead';
}

/**
 * A reading this environment says may not be given away.
 *
 * Used to prove the refusal rather than assumed to exist: the test that wants
 * it reads the catalogue and skips if every published product is giftable.
 */
function giftWalkUngiftableKey(): string
{
    $key = getenv('STAGING_UNGIFTABLE_KEY');

    return is_string($key) && $key !== '' ? $key : 'one-card';
}

/**
 * What this environment is publishing, in the walk's language.
 *
 * @return array<int, array<string, mixed>>
 */
function giftWalkProducts(): array
{
    $response = Target::client()->get('api/v1/'.giftWalkLocale().'/products');

    if ($response->getStatusCode() !== 200) {
        test()->markTestSkipped(
            'The products endpoint answered '.$response->getStatusCode().' for '.giftWalkLocale()
            .', so there is no catalogue to gift anything out of. Response: '.$response->getBody()
        );
    }

    /** @var array<int, array<string, mixed>> $products */
    $products = Target::json($response);

    return $products;
}

/**
 * The intent a client secret belongs to.
 *
 * `pi_3Abc_secret_xyz` names `pi_3Abc`. Split rather than parsed, because the
 * only part of the shape being relied on is the separator Stripe documents.
 */
function giftIntentIdIn(string $clientSecret): string
{
    return explode('_secret_', $clientSecret, 2)[0];
}

/**
 * Confirms a payment the way the customer's browser would.
 *
 * Raw HTTP to Stripe with a publishable key and the client secret, which is the
 * call Stripe.js makes. Nothing of ours is involved on this side.
 *
 * **This reaches the real Stripe API, and that is deliberate.** It is the one
 * carve-out in `.claude/testing.md`, granted 26 August 2026 to `tests/Staging/`
 * alone. Test mode only, off unless `STAGING_GIFT=1` is asked for, never
 * against production, never in CI. Read that section before copying this shape
 * anywhere else, because everywhere else the rule is still absolute.
 */
function confirmGiftIntentAtStripe(string $clientSecret, string $paymentMethod): ResponseInterface
{
    return (new Client(['http_errors' => false, 'timeout' => 30]))->post(
        'https://api.stripe.com/v1/payment_intents/'.giftIntentIdIn($clientSecret).'/confirm',
        [
            'headers' => [
                'Authorization' => 'Bearer '.giftWalkPublishableKey(),
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
 * The code, from whoever actually received it.
 *
 * **This is the part of the walk that is a person, and it cannot be anything
 * else.** The code is minted at settlement and mailed to the recipient. It is
 * absent from the order response by explicit decision, it is not on any other
 * endpoint, and the only readers of it anywhere are that inbox and the panel's
 * Gifts screen. A walk that could fetch it over HTTP would be a walk describing
 * a system where the buyer can read their own present's credential.
 *
 * `STAGING_GIFT_CODE` short circuits the wait, and it is for one case: a run
 * that failed **after** the payment settled and **before** the code was spent.
 * Re-running the whole walk to get past a broken assertion otherwise costs
 * another real payment. A code that has already been redeemed is no use here,
 * because redeeming it again is a 409 by design.
 */
function giftCodeFromWhoeverReceivedIt(string $recipient, int $seconds): string
{
    $supplied = (string) getenv('STAGING_GIFT_CODE');

    if (trim($supplied) !== '') {
        Target::say('Using the code from STAGING_GIFT_CODE, so nothing was read out of an inbox.');

        return trim($supplied);
    }

    Target::say(str_repeat('=', 72));
    Target::say('The payment has gone through. A code should now be on its way to:');
    Target::say('');
    Target::say('    '.$recipient);
    Target::say('');
    Target::say('Look for the message subject-lined "... has sent you a reading" and type the');
    Target::say('code below. It is twelve characters in three groups, and the hyphens, the');
    Target::say('spaces and the case do not matter: the endpoint normalises whatever you send.');
    Target::say('');
    Target::say('If nothing arrives, the code is also on the Gifts screen at '.Target::url('/admin').'.');
    Target::say('Which of the two you had to use is worth writing down, because a code that');
    Target::say('exists in the panel and never reached an inbox is a present nobody can open.');
    Target::say('');
    Target::say('Waiting '.$seconds.' seconds. Enter to give up.');
    Target::say(str_repeat('=', 72));

    $deadline = time() + $seconds;

    while (time() < $deadline) {
        $readable = [STDIN];
        $writable = null;
        $except = null;

        // **A timeout rather than a blocking read**, so a run that nobody is
        // watching ends with a message rather than holding a terminal open
        // until somebody notices.
        if (stream_select($readable, $writable, $except, 15) === false) {
            break;
        }

        if ($readable === []) {
            Target::say('Still waiting for the code. '.($deadline - time()).' seconds left.');

            continue;
        }

        $typed = fgets(STDIN);

        // **False is nothing attached to standard input at all**, which is a
        // run started without a terminal rather than a person who has not
        // typed yet. Waiting the full deadline for a stream that can never
        // deliver is the one way this could look like a slow tester.
        if ($typed === false) {
            test()->fail(
                'Nothing is attached to standard input, so there is nobody to read the code out of an inbox. '
                .'Run this walk from a terminal, or set STAGING_GIFT_CODE to a code you already hold.'
            );
        }

        if (trim($typed) !== '') {
            return trim($typed);
        }

        test()->fail(
            'No code was entered, so the walk stopped where a recipient would have. A real payment has been taken '
            .'on this environment and the present it bought has not been opened.'
        );
    }

    test()->fail(
        'No code arrived within '.$seconds.' seconds of the payment settling. Two things look identical from here '
        .'and both are the failure this walk exists to find: the payment never settled on this environment, so no '
        .'code was ever minted, or it settled and the gift mail went nowhere. Check the Gifts screen at '
        .Target::url('/admin').' first, because that tells the two apart in one look. If the code is there, the '
        .'mail is the problem: php artisan mail:check on the environment console, once with --now and once without, '
        .'which is the transport and the queue worker separately.'
    );
}

it('says which readings may be given away, and answers for every product', function (): void {
    $products = giftWalkProducts();

    expect($products)->not->toBeEmpty('This environment publishes nothing in '.giftWalkLocale().'.');

    foreach ($products as $product) {
        // **Present on every product, not only on the giftable ones.** The
        // frontend draws its toggle off this field and cannot tell an absent
        // key from a false one, so a product missing it is a reading whose
        // gift button silently never appears.
        //
        // **`array_key_exists` rather than `toHaveKey`**, because that matcher
        // reads its second argument as the value it expects rather than as the
        // message to fail with, and a message passed to it is asserted against
        // the field. Found on this file's first execution, which is the whole
        // argument for running one.
        expect(array_key_exists('is_giftable', $product))->toBeTrue(
            'Product ['.($product['key'] ?? '?').'] carries no is_giftable, so this environment does not have the '
            .'gifting work deployed. Nothing else in this file can pass.'
        );

        expect($product['is_giftable'])->toBeBool();
    }

    $giftable = array_values(array_filter($products, fn (array $p): bool => $p['is_giftable'] === true));

    expect($giftable)->not->toBeEmpty(
        'Nothing in this catalogue may be given away, so there is no present to buy. is_giftable is seeded from '
        .'the product key and re-applied on every seeder run, so an empty list here means the seeder has not run '
        .'on this environment since gifting landed.'
    );

    Target::say(count($giftable).' of '.count($products).' published products may be given away.');
});

/**
 * **Refused rather than quietly dropped**, which is the whole of the rule.
 * Taking the order and discarding the gift charges somebody for a present that
 * was never sent, and nobody would ever find out: the buyer never receives the
 * code, so the only person who could notice is the one who was never written
 * to.
 */
it('refuses a gift on a reading that cannot be given away, and names the line', function (): void {
    $products = giftWalkProducts();

    $ungiftable = array_values(array_filter(
        $products,
        fn (array $p): bool => ($p['key'] ?? null) === giftWalkUngiftableKey(),
    ));

    if ($ungiftable === []) {
        $this->markTestSkipped(
            giftWalkUngiftableKey().' is not published in '.giftWalkLocale().', so there is nothing here that '
            .'refuses a gift. Name another with STAGING_UNGIFTABLE_KEY.'
        );
    }

    if ($ungiftable[0]['is_giftable'] !== false) {
        $this->markTestSkipped(
            giftWalkUngiftableKey().' is giftable on this environment, so it cannot prove a refusal. Name a '
            .'product that is not with STAGING_UNGIFTABLE_KEY.'
        );
    }

    $response = Target::client()->post('api/v1/orders', [
        'headers' => Target::as(Target::sharedAccount()),
        'json' => [
            'currency' => giftWalkCurrency(),
            'lines' => [[
                'product' => giftWalkUngiftableKey(),
                'gift' => [
                    'recipient_email' => Target::freshEmail('gift-refused'),
                    'signature' => 'Staging Walk',
                ],
            ]],
        ],
    ]);

    Target::assertNotThrottled($response, 'orders');

    expect($response->getStatusCode())->toBe(422);

    $errors = Target::json($response)['errors'] ?? [];

    // **A refusal about the product is a catalogue that is not ready, not a
    // rule that failed.** Nothing is said about a line's gift once the product
    // itself has been refused, so an unpriced reading here would look exactly
    // like a gift rule that never ran.
    if (array_key_exists('lines.0.product', $errors)) {
        $this->markTestSkipped(
            giftWalkUngiftableKey().' cannot be bought in '.giftWalkCurrency().' here at all, so the gift rule was '
            .'never reached. Publish it and price it above zero first. Response: '.$response->getBody()
        );
    }

    // Keyed to the line and not to the order, which is what the frontend can
    // put next to the field somebody filled in.
    expect(array_key_exists('lines.0.gift', $errors))->toBeTrue(
        'The gift was neither refused nor named on the line it was wrong on, so a buyer who gifts something that '
        .'cannot be given away is either charged for a present nobody receives or told to fix a field they cannot '
        .'find. Response: '.$response->getBody()
    );
});

/**
 * From outside, a code nobody was given and a code typed wrong have to be the
 * same thing. Telling them apart is a way of asking whether a guess got closer,
 * and a gift code is a bearer credential with a reading behind it.
 */
it('answers the same 404 to a code nobody was given, on both endpoints', function (): void {
    $client = Target::client();

    // Twelve characters out of the alphabet a real code is drawn from, so this
    // misses on being unknown rather than on being the wrong shape. Nothing on
    // either endpoint validates the shape, deliberately.
    $unknown = 'ZZZZ-ZZZZ-ZZZZ';

    $lookup = $client->post('api/v1/'.giftWalkLocale().'/gifts/lookup', [
        'json' => ['code' => $unknown],
    ]);

    Target::assertNotThrottled($lookup, 'gifts-lookup');

    expect($lookup->getStatusCode())->toBe(404);

    // **And the same answer to nonsense**, which normalises to nothing and then
    // misses, rather than being refused for its shape. A 422 here and a 404
    // above would be two different answers to two different guesses.
    $nonsense = $client->post('api/v1/'.giftWalkLocale().'/gifts/lookup', [
        'json' => ['code' => '???'],
    ]);

    Target::assertNotThrottled($nonsense, 'gifts-lookup');

    expect($nonsense->getStatusCode())->toBe(
        404,
        'A code that normalises to nothing was answered differently from one that simply does not exist. Response: '
        .$nonsense->getBody()
    );

    $redeem = $client->post('api/v1/'.giftWalkLocale().'/gifts/redeem', [
        'json' => [
            'code' => $unknown,
            'question' => 'What should I focus on this month?',
            'querent_email' => Target::freshEmail('gift-unknown'),
        ],
    ]);

    Target::assertNotThrottled($redeem, 'gifts-redeem');

    expect($redeem->getStatusCode())->toBe(404, 'Response: '.$redeem->getBody());
});

it('buys a present, hands over its code, and starts the clock on the day it is redeemed', function (): void {
    if ($reason = giftWalkCannotPay()) {
        $this->markTestSkipped($reason);
    }

    $client = Target::client();

    $giftKey = giftWalkGiftKey();
    $catalogue = giftWalkProducts();

    $chosen = array_values(array_filter($catalogue, fn (array $p): bool => ($p['key'] ?? null) === $giftKey));

    if ($chosen === [] || ($chosen[0]['is_giftable'] ?? false) !== true) {
        $this->markTestSkipped(
            $giftKey.' is not a giftable published product in '.giftWalkLocale().' on this environment, so there is '
            .'no present to buy. Name another with STAGING_GIFT_KEY.'
        );
    }

    // **Three people, and they are three on purpose.** The buyer pays. The
    // recipient is the address the code is mailed to. The querent is whoever
    // ends up asking, and it is a third address because a forwarded gift is
    // enough to part the last two: the redemption asks for a querent rather
    // than inheriting the recipient, and a walk using one address for both
    // could never tell whether it does.
    $buyer = Target::register('gift-buyer');
    $recipient = Target::freshEmail('gift-recipient');
    $querent = Target::freshEmail('gift-querent');

    $asBuyer = Target::as($buyer);

    $placed = $client->post('api/v1/orders', [
        'headers' => $asBuyer,
        'json' => [
            'currency' => giftWalkCurrency(),
            'lines' => [[
                'product' => $giftKey,
                'gift' => [
                    'recipient_email' => $recipient,
                    'signature' => 'The Staging Walk',
                    'message' => 'Bought by a test on '.date('j F Y').'. Nobody is expecting this.',
                    'locale' => giftWalkLocale(),
                ],
            ]],
        ],
    ]);

    Target::assertNotThrottled($placed, 'orders');

    if ($placed->getStatusCode() === 422) {
        $refusals = Target::json($placed)['errors'] ?? [];

        // **Only a refusal about the product is a catalogue that is not
        // ready.** Anything keyed to the gift is this environment refusing a
        // present it should have taken, which is the defect this walk exists
        // to find, and skipping on it would report a broken gifting path as an
        // unpriced reading.
        if (! array_key_exists('lines.0.product', $refusals)) {
            $this->fail(
                'The order was refused for a reason that is not the catalogue: '.implode(', ', array_keys($refusals))
                .'. This environment says '.$giftKey.' may be given away and then would not take the gift. '
                .'Response: '.$placed->getBody()
            );
        }

        $this->markTestSkipped(
            $giftKey.' cannot be bought in '.giftWalkCurrency().' here. Publish it and price it above zero in the '
            .'panel first. Response: '.$placed->getBody()
        );
    }

    expect($placed->getStatusCode())->toBe(201);

    $order = Target::json($placed);
    $line = $order['lines'][0];

    expect($order['status'])->toBe('pending')
        ->and($order['pay_token'] ?? null)->toBeString();

    // The address is the only part of a present the buyer can ever check, and
    // this response is the one place it is shown back to them.
    expect($line['gift']['recipient_email'] ?? null)->toBe($recipient)
        ->and($line['gift']['signature'] ?? null)->toBe('The Staging Walk');

    // **No question on a gift line.** The person who will ask has not seen the
    // present yet, and asking is what redemption is for.
    expect($line['question'])->toBeNull();

    // **And no code, which is the assertion this response exists to fail.** It
    // does not exist yet, because a code minted before the money arrives is a
    // live bearer credential behind every abandoned checkout. Asked of the gift
    // object and of the whole body, since a code appearing anywhere in what the
    // buyer is handed is the same defect wherever it landed.
    expect($line['gift'])->not->toHaveKey('code');
    expect(strtolower((string) $placed->getBody()))->not->toContain('"code"');

    Target::say('Order #'.$order['id'].' placed by '.$buyer['email'].' as a present for '.$recipient.'.');

    $paying = $client->post('api/v1/orders/'.$order['pay_token'].'/pay', [
        'headers' => $asBuyer,
        'json' => ['method' => 'stripe_wallet'],
    ]);

    Target::assertNotThrottled($paying, 'orders-pay');

    if ($paying->getStatusCode() === 422) {
        $this->markTestSkipped(
            'This environment does not offer stripe_wallet, so there is no secret a terminal can confirm against. '
            .'It needs BOTH STRIPE_SECRET and STRIPE_WEBHOOK_SECRET filled. Response: '.$paying->getBody()
        );
    }

    if ($paying->getStatusCode() !== 200) {
        $this->fail('The pay endpoint answered '.$paying->getStatusCode().'. Response: '.$paying->getBody());
    }

    $instruction = Target::json($paying);

    expect($instruction['type'])->toBe('client_secret')
        ->and($instruction['client_secret'] ?? null)->toBeString();

    $confirmed = confirmGiftIntentAtStripe((string) $instruction['client_secret'], 'pm_card_visa');
    $stripe = Target::json($confirmed);

    if ($confirmed->getStatusCode() !== 200) {
        $this->fail(
            'Stripe refused the confirmation with '.$confirmed->getStatusCode().'. This is Stripe talking, not us, '
            .'so read its message before touching anything here: '.$confirmed->getBody()
        );
    }

    // Stripe's own word that the money moved. Everything after this is about
    // whether the deployed environment noticed, and about who it told.
    expect($stripe['status'] ?? null)->toBe('succeeded')
        ->and($stripe['amount'] ?? null)->toBe($order['total_amount']);

    $paidAt = time();

    // **Stripe's answer, read back through this environment**, which says the
    // payment is definitely there and says nothing at all about whether we
    // settled it. It is here so that a code failing to arrive can be told apart
    // from a payment that never happened.
    $status = $client->post('api/v1/orders/status', [
        'json' => ['pay_token' => $order['pay_token']],
    ]);

    Target::assertNotThrottled($status, 'orders-status');

    expect(Target::json($status)['status'] ?? null)->toBe('succeeded', 'Response: '.$status->getBody());

    $code = giftCodeFromWhoeverReceivedIt($recipient, 900);
    $waited = time() - $paidAt;

    Target::say('A code arrived '.$waited.' seconds after the payment, so this environment settled the order.');

    // **What that proves is that the order was settled here, and not by which
    // road.** The webhook, the fifteen minute sweep and somebody pressing
    // `Mark as paid` all reach `MarkOrderPaid`, and a code exists after any of
    // them. Naming the webhook off a stopwatch would be a conclusion drawn from
    // how quickly a person read an email. `WalletPaymentWalkTest` is what
    // proves the endpoint is registered and reachable, by watching for
    // something no other road could have caused.
    //
    // Said out loud rather than left implied, because the first run of this
    // file printed the opposite and it was wrong: the code that run received
    // had been minted by a hand settlement.
    //
    // **Two minutes, matching the window the wallet walk draws its own
    // conclusion inside.** It was fifteen, which is the deadline the wait above
    // gives up at, so the branch could never be false and the line printed
    // after a fourteen minute wait as readily as after ten seconds.
    if ($waited < 120) {
        Target::say('Fast enough that nobody could have settled it by hand meanwhile, though this cannot tell.');
    }

    $lookupPath = 'api/v1/'.giftWalkLocale().'/gifts/lookup';

    $lookup = $client->post($lookupPath, ['json' => ['code' => $code]]);

    Target::assertNotThrottled($lookup, 'gifts-lookup');

    if ($lookup->getStatusCode() === 404) {
        $this->fail(
            'The code was not recognised by this environment: '.$code.'. If it was typed off a screen, one of the '
            .'characters is wrong. If it was copied, the gift it belongs to is on another environment.'
        );
    }

    expect($lookup->getStatusCode())->toBe(200)
        ->and($lookup->getHeaderLine('Cache-Control'))->toContain('no-store');

    $present = Target::json($lookup);

    expect($present['product'])->toBe($giftKey)
        ->and($present['name'])->toBeString()->not->toBe('')
        ->and($present['redeemed'])->toBeFalse()
        ->and($present['redeemed_at'])->toBeNull();

    // Grouped for the eye, whatever was typed. Three groups of four, so the
    // visitor can see what was resolved out of what they entered.
    expect($present['code'])->toMatch('/^[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/');

    // **Never the buyer.** This endpoint answers to anybody holding a code,
    // which is not necessarily the person it was sent to.
    expect($present)->not->toHaveKey('signature');
    expect($present)->not->toHaveKey('message');
    expect($present)->not->toHaveKey('recipient_email');

    // **Looked up twice before anything is spent**, which is the reason there
    // are two endpoints at all. Email scanners and link prefetchers follow
    // links, and a present opened by a scanner cannot be given back: there is
    // no expiry to outlive it and no refund to settle it with.
    $again = $client->post($lookupPath, ['json' => ['code' => $code]]);

    Target::assertNotThrottled($again, 'gifts-lookup');

    // **Asked before the answer is read**, because a body that is not a lookup
    // decodes to an array with no `redeemed` in it, and the expectation below
    // would then accuse this system of spending somebody's present when all
    // that happened was a 429 or a 500.
    expect($again->getStatusCode())->toBe(200, 'The second lookup answered: '.$again->getBody());

    expect(Target::json($again)['redeemed'])->toBeFalse(
        'Looking a code up spent it. Whatever opens the recipient first gets their present, and there is nothing '
        .'that can put that right.'
    );

    Target::say('Looked the code up twice and it is still unspent. Redeeming it as somebody else.');

    // **A second session, and genuinely a different person.** Its own client
    // with its own cookie jar, and no Authorization header anywhere on it, so
    // nothing the buyer holds is being used to spend this. That is the shape
    // the recipient is actually in: they have an email and no account, and by
    // ADR 0004 they deliberately never get one.
    $recipientSession = Target::client(new CookieJar);

    // Stamped with the instant it was asked, so the copy that reaches orders@
    // can be matched to this run by eye rather than by its timestamp.
    $question = 'Walked on '.date('c').'. What should I focus on this month?';

    $redeemedAt = time();

    $redeemed = $recipientSession->post('api/v1/'.giftWalkLocale().'/gifts/redeem', [
        'json' => [
            'code' => $code,
            'question' => $question,
            'querent_email' => $querent,
            'querent_name' => 'The Querent',
        ],
    ]);

    Target::assertNotThrottled($redeemed, 'gifts-redeem');

    expect($redeemed->getStatusCode())->toBe(200, 'Response: '.$redeemed->getBody())
        ->and($redeemed->getHeaderLine('Cache-Control'))->toContain('no-store');

    $reading = Target::json($redeemed);

    expect($reading['product'])->toBe($giftKey)
        ->and($reading['question'])->toBe(
            $question,
            'The question came back changed. It is the question of record, the only thing anybody writes a reading '
            .'from, and the confirmation is where the querent checks it is what they typed.'
        );

    // **The querent is who asked, not who the present was posted to.** A
    // forwarded gift parts those two, and an environment inheriting the
    // recipient silently would deliver the reading to whoever forwarded it.
    expect($reading['querent_email'])->toBe(
        $querent,
        'The reading was addressed to '.($reading['querent_email'] ?? 'nobody').' rather than to the person who '
        .'asked for it. The gift was posted to '.$recipient.', and inheriting that address is exactly the defect '
        .'asking for a querent exists to prevent.'
    );

    // **The clock starts here and not at the purchase.** `asked_at` is the
    // instant `AskForReading` ran, which is the instant Jennifer was told, and
    // it is what every delivery window in the panel is counted from.
    $asked = strtotime((string) $reading['asked_at']);

    //
    // **The tolerance is not slack, it is two clocks.** `asked_at` is the
    // deployed environment's `now()` and `$paidAt` is this runner's, and
    // nothing keeps a laptop and a server in step. The assertion is that the
    // reading was not stamped at the purchase, and the purchase is the only
    // thing this could be confused with, so five minutes is far tighter than
    // the months a real gift sits between the two and far looser than any
    // skew.
    expect($asked)->toBeGreaterThanOrEqual(
        $paidAt - 300,
        'The reading was stamped as asked before the payment that bought it, so asked_at is not being written at '
        .'redemption.'
    );

    expect(abs($asked - $redeemedAt))->toBeLessThan(
        300,
        'asked_at is '.abs($asked - $redeemedAt).' seconds from the moment this walk redeemed the code. It is '
        .'meant to be the moment of redemption, and every due date on the readings screen is counted from it.'
    );

    // **And once.** A second submit of the same code is refused rather than
    // served, so there is no second reading and no second message to Jennifer.
    // `RedeemGift` takes a row lock to make this true under two real tabs;
    // what is observable from here is that the answer is a 409 and not a 404,
    // because the real recipient has to be told which of the two happened.
    $twice = $recipientSession->post('api/v1/'.giftWalkLocale().'/gifts/redeem', [
        'json' => [
            'code' => $code,
            'question' => 'Asked a second time, and this must not produce a second reading.',
            'querent_email' => $querent,
        ],
    ]);

    expect($twice->getStatusCode())->toBe(
        409,
        'A code was spent twice, or was answered as though it had never existed. Either way somebody has a second '
        .'reading nobody paid for, or a recipient is being told to retype a code that will never work again. '
        .'Response: '.$twice->getBody()
    );

    expect(Target::json($twice)['errors'] ?? [])->toHaveKey('code');

    $after = $client->post($lookupPath, ['json' => ['code' => $code]]);

    Target::assertNotThrottled($after, 'gifts-lookup');

    expect($after->getStatusCode())->toBe(200, 'The lookup after redeeming answered: '.$after->getBody());

    $spent = Target::json($after);

    expect($spent['redeemed'])->toBeTrue()
        ->and($spent['redeemed_at'])->toBeString();

    Target::say(str_repeat('=', 72));
    Target::say('The walk is done, and everything an HTTP client can see has passed.');
    Target::say('');
    Target::say('  Settled at   '.date('H:i:s', $paidAt));
    Target::say('  Redeemed at  '.date('H:i:s', $redeemedAt));
    Target::say('');
    Target::say('What is left is a mailbox, and no assertion above can reach one. Four messages');
    Target::say('should have arrived, and the last one is the whole point of this unit:');
    Target::say('');
    Target::say('  1. "Your order is confirmed" to '.$buyer['email'].', at '.date('H:i:s', $paidAt));
    Target::say('     naming '.$recipient.' and carrying no code.');
    Target::say('  2. "The Staging Walk has sent you a reading" to '.$recipient.',');
    Target::say('     at '.date('H:i:s', $paidAt).', carrying the code you typed in.');
    Target::say('  3. "Your reading is on its way" to '.$querent.',');
    Target::say('     at '.date('H:i:s', $redeemedAt).', stating the delivery window.');
    Target::say('  4. "A reading is waiting to be written" to orders@, and this is the one to');
    Target::say('     check properly. **There must be exactly one, and it must carry the');
    Target::say('     question below.** That is the exact check rather than the timestamp: a');
    Target::say('     copy sent at settlement can only say "No question was asked", because');
    Target::say('     nobody had asked anything, and it would mean a gift reached her working');
    Target::say('     list on the day it was bought with nothing on it to write.');
    Target::say('');
    Target::say('     The times above are a guide and not the test. These are queued messages,');
    Target::say('     so what a mailbox shows is when the worker sent one rather than when');
    Target::say('     something asked it to, and a queue that is behind moves both.');
    Target::say('');
    Target::say('     The question it must carry:');
    Target::say('     '.$question);
    Target::say(str_repeat('=', 72));
});
