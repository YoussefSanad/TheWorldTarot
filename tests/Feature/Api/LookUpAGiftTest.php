<?php

use App\Enums\LocaleStatus;
use App\Models\Locale;
use App\Models\Reading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
| What a code is worth, answered without spending it.
|
| B7, issue #73. The frontend's `docs/adr/0003-redemption-is-a-page-of-its-own.md`
| requires this call to exist at all: email scanners and link prefetchers follow
| links, so a visitor who opens `/redeem/` and closes the tab must still have
| their present. **Looking up and spending are two calls for that one reason**,
| and the case below that counts the readings afterwards is the whole of it.
|
| **A POST for a read, deliberately.** The code is a bearer credential, and a
| path segment or a query string lands in every access log and every proxy
| between the browser and here. `POST /orders/status` takes the pay token the
| same way and for the same reason.
|
| **It answers in full and does not hedge.** A code already spent says so, and
| says when, because that is a state the real recipient has to be told about and
| it cannot hide behind the answer an unknown code gets. What closes the oracle
| is the sixty bits in the code, which `tests/Unit/GiftCodeTest.php` pins, plus
| the throttle at the bottom of this file.
|
| `RedeemAGiftTest` owns spending. `RedeemAGiftRaceTest` owns the lock.
*/

/**
 * The code as the mail printed it for the recipient.
 *
 * Written out rather than drawn, because every case here is about reading a
 * particular string back and a random one would make the expectations unwritable.
 */
const PRINTED = 'K7M4-9PQR-2XYZ';

const STORED_CODE = 'K7M49PQR2XYZ';

function lookingUp(string $typed, string $locale = 'en'): TestResponse
{
    return test()->postJson("/api/v1/{$locale}/gifts/lookup", ['code' => $typed]);
}

it('answers what a code is worth', function () {
    aGiftOf(['code' => STORED_CODE], [
        'name' => ['en' => 'MONTH AHEAD'],
        'short_description' => ['en' => 'Where the next month is going.'],
        'long_description' => ['en' => 'Four cards, and a page about each of them.'],
    ]);

    // The agreed shape, exactly, because `/redeem/` is built against it. The
    // copy travels with the answer rather than being fetched from
    // `/products/{key}`, which 404s on anything unpublished.
    lookingUp(STORED_CODE)
        ->assertOk()
        ->assertExactJson([
            'code' => PRINTED,
            'product' => 'month-ahead',
            'name' => 'MONTH AHEAD',
            'short_description' => 'Where the next month is going.',
            'long_description' => 'Four cards, and a page about each of them.',
            'redeemed' => false,
            'redeemed_at' => null,
        ]);
});

it('does not spend the code it just answered about', function () {
    $gift = aGiftOf(['code' => STORED_CODE]);

    lookingUp(STORED_CODE)->assertOk();

    // **The reason there are two endpoints.** A scanner that follows the link
    // in the recipient's mail has now read this, and the present is still
    // theirs.
    expect($gift->refresh()->isRedeemed())->toBeFalse()
        ->and($gift->redeemed_at)->toBeNull()
        ->and(Reading::count())->toBe(0);
});

it('comes back in the printed form, whatever was typed', function () {
    aGiftOf(['code' => STORED_CODE]);

    foreach ([PRINTED, 'k7m4 9pqr 2xyz', 'k7m49pqr2xyz', '  K7M4.9PQR.2XYZ  '] as $typed) {
        // Echoed back grouped so the visitor can see what was resolved from
        // what they typed.
        lookingUp($typed)->assertOk()->assertJsonPath('code', PRINTED);
    }
});

it('reads the three glyphs that go wrong between one screen and another', function () {
    // `I` and `L` as `1`, `O` as `0`. Unambiguous only because a minted code
    // can contain none of them, which `tests/Unit/GiftCodeTest.php` holds still.
    aGiftOf(['code' => '1234567890AB']);

    lookingUp('I234-5678-90AB')->assertOk()->assertJsonPath('code', '1234-5678-90AB');
    lookingUp('l234 5678 9oab')->assertOk()->assertJsonPath('code', '1234-5678-90AB');
});

it('says in plain words that a code has already been spent', function () {
    $spentOn = now()->subDays(3)->startOfSecond();

    aGiftOf(['code' => STORED_CODE, 'redeemed_at' => $spentOn]);

    // It cannot answer vaguely here. Somebody holding a present that has
    // already been opened has to be told that, rather than told the same
    // nothing a stranger guessing gets.
    lookingUp(STORED_CODE)
        ->assertOk()
        ->assertJsonPath('redeemed', true)
        ->assertJsonPath('redeemed_at', $spentOn->toIso8601String());
});

it('answers 404 for a code nobody was given', function () {
    aGiftOf(['code' => STORED_CODE]);

    lookingUp('ZZZZ-ZZZZ-ZZZZ')
        ->assertNotFound()
        // An `HttpException` with no message renders as `{"message": ""}` once
        // debug is off, which is a 404 with nothing in it.
        ->assertJsonPath('message', fn (mixed $message): bool => is_string($message) && $message !== '');
});

it('makes no distinction between a code that is wrong and one that is nonsense', function () {
    aGiftOf(['code' => STORED_CODE]);

    // Both 404. Telling them apart is a way of asking whether a guess got
    // closer, which is why nothing in `LookUpGiftRequest` describes the shape
    // of a real code.
    expect(lookingUp('ZZZZ-ZZZZ-ZZZZ')->status())->toBe(404)
        ->and(lookingUp('----')->status())->toBe(404)
        ->and(lookingUp('not a code at all')->status())->toBe(404);
});

it('cannot be used to find a present whose money never arrived', function () {
    // A gift row exists from placement and its code does not. Nothing may
    // resolve one: an unminted gift is inert, which is what makes an abandoned
    // checkout leave no live credential behind.
    aGiftOf(['code' => null]);

    // A unique index does not treat nulls as equal, so an unminted row is not
    // something two abandoned checkouts collide on and not something an empty
    // lookup lands on either.
    lookingUp('----')->assertNotFound();
    lookingUp('ZZZZ-ZZZZ-ZZZZ')->assertNotFound();
});

it('resolves a code for a reading nobody can buy any more', function () {
    aGiftOf(['code' => STORED_CODE], [
        'is_published' => false,
        'name' => ['en' => 'MONTH AHEAD'],
    ]);

    // **The one place in this system that reads a product past
    // `is_published`.** The person paid. Pinned here so that nobody later adds
    // a published check to match every other read of the catalogue.
    lookingUp(STORED_CODE)
        ->assertOk()
        ->assertJsonPath('name', 'MONTH AHEAD')
        ->assertJsonPath('product', 'month-ahead');
});

it('resolves a code for a reading that has been retired outright', function () {
    $gift = aGiftOf(['code' => STORED_CODE], ['name' => ['en' => 'MONTH AHEAD']]);

    $gift->orderLine->product->delete();

    // Products soft delete precisely so an order can go on referring to them.
    // A retired product with a code outstanding is the case that makes that
    // load bearing rather than tidy.
    lookingUp(STORED_CODE)->assertOk()->assertJsonPath('name', 'MONTH AHEAD');
});

it('answers in the language of the path', function () {
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Live]);

    // Fully translated, since a language is not servable at all while any
    // record in it is incomplete.
    aGiftOf(['code' => STORED_CODE], [
        'name' => ['en' => 'MONTH AHEAD', 'es' => 'EL MES QUE VIENE'],
        'short_description' => ['en' => 'The next month.', 'es' => 'El proximo mes.'],
        'long_description' => ['en' => 'The long one.', 'es' => 'La larga.'],
    ]);

    // Which is why the copy is here at all. `/redeem/` renders a page about a
    // reading somebody was given, in the language they opened the link in.
    lookingUp(STORED_CODE, 'es')->assertOk()->assertJsonPath('name', 'EL MES QUE VIENE');
});

it('refuses a request carrying no code', function () {
    // Required, and nothing beyond that. A rule describing what a real code
    // looks like would answer 422 for a shape no gift has and 404 for a shape
    // some gift might, and the difference between those two answers is a way of
    // asking whether a guess got closer.
    $this->postJson('/api/v1/en/gifts/lookup', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('code');

    lookingUp('')->assertStatus(422)->assertJsonValidationErrors('code');
});

it('lets nothing keep the answer', function () {
    aGiftOf(['code' => STORED_CODE]);

    // A response naming what a bearer credential is worth is not cacheable by
    // a browser, by a service worker, or by anything in between.
    lookingUp(STORED_CODE)->assertHeader('Cache-Control', 'no-store, private');
});

/**
 * The tighter of the two limits, because this is the endpoint somebody would
 * guess against. It is not what closes the oracle, which is the entropy, and it
 * is what stops one caller asking about codes all day.
 */
it('refuses somebody working through codes', function () {
    foreach (range(1, 10) as $attempt) {
        lookingUp("ZZZZZZZZZZ{$attempt}Z")->assertNotFound();
    }

    lookingUp('ZZZZ-ZZZZ-ZZZZ')->assertStatus(429)->assertHeader('Retry-After');
});

/**
 * The limiter's key prefix is load bearing, per the comment at the top of
 * `routes/api.php`. Without it every throttled route shares one counter per
 * address.
 */
it('spends its own rate limit rather than the redemption endpoint\'s', function () {
    aGiftOf(['code' => STORED_CODE]);

    foreach (range(1, 10) as $attempt) {
        lookingUp("ZZZZZZZZZZ{$attempt}Z")->assertNotFound();
    }

    lookingUp('ZZZZ-ZZZZ-ZZZZ')->assertStatus(429);

    // Somebody who mistyped their code ten times must still be able to redeem
    // the present they were actually given.
    expect($this->postJson('/api/v1/en/gifts/redeem', [
        'code' => STORED_CODE,
        'question' => 'What should I focus on this month?',
        'querent_email' => 'sam@example.com',
    ])->status())->toBe(200);
});
