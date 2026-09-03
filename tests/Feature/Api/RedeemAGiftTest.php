<?php

use App\Actions\AskForReading;
use App\Actions\RedeemGift;
use App\Mail\ReadingOnItsWay;
use App\Mail\ReadingToWrite;
use App\Models\Gift;
use App\Models\Reading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

/*
| Spending the code, which is the moment somebody asks for the reading.
|
| B7, issue #73. `docs/adr/0004-a-reading-is-a-row-of-its-own.md` is why there is
| a row to create at all: a gift bought in September and redeemed in December
| pulls "the money arrived" and "somebody asked" three months apart, and this is
| the second of those two days.
|
| **Redemption collects nothing.** It is not a payment method, it answers the
| confirmation itself, and it never reaches `POST /orders/status`, which reports
| a payment that happened months earlier to somebody else.
|
| `LookUpAGiftTest` owns reading a code without spending it.
| `RedeemAGiftRaceTest` owns the lock, which is the gate on this unit.
*/

/**
 * A code as the recipient's mail printed it, and the code as it is stored.
 *
 * Written out rather than drawn, for the reason `LookUpAGiftTest` gives.
 */
const SPENDABLE = 'K7M49PQR2XYZ';

/**
 * @param  array<string, mixed>  $fields
 */
function redeeming(array $fields = [], string $locale = 'en'): TestResponse
{
    return test()->postJson("/api/v1/{$locale}/gifts/redeem", [
        'code' => SPENDABLE,
        'question' => 'What should I focus on this month?',
        'querent_email' => 'sam@example.com',
        'querent_name' => 'Sam',
        ...$fields,
    ]);
}

it('answers the confirmation itself', function () {
    aGiftOf(['code' => SPENDABLE], ['name' => ['en' => 'MONTH AHEAD']]);

    // The agreed shape, exactly. The frontend renders its confirmation from
    // this rather than from a second call, because the only other endpoint that
    // could answer one is about money and no money moves here.
    $response = redeeming()->assertOk();

    $response->assertExactJson([
        'product' => 'month-ahead',
        'name' => 'MONTH AHEAD',
        'question' => 'What should I focus on this month?',
        'querent_email' => 'sam@example.com',
        'asked_at' => Reading::sole()->asked_at->toIso8601String(),
    ]);
});

it('creates the reading somebody is now waiting for', function () {
    $gift = aGiftOf(['code' => SPENDABLE]);

    redeeming()->assertOk();

    $reading = Reading::sole();

    // **The question of record is the querent's, not the buyer's.**
    // `order_lines.question` is null on a gift line: the person who asks has
    // not seen the present at the point the buyer types anything.
    expect($reading->question)->toBe('What should I focus on this month?')
        ->and($reading->querent_email)->toBe('sam@example.com')
        ->and($reading->querent_name)->toBe('Sam')
        ->and($reading->gift_id)->toBe($gift->id)
        ->and($reading->isAGift())->toBeTrue()
        ->and($reading->order_line_id)->toBe($gift->order_line_id)
        ->and($reading->delivered_at)->toBeNull()
        ->and($gift->orderLine->question)->toBeNull();
});

it('stamps the present as spent', function () {
    $gift = aGiftOf(['code' => SPENDABLE]);

    redeeming()->assertOk();

    // Null was an obligation still on the books. This is the moment it is
    // discharged, and the Gifts screen B9 builds is where it stops being
    // outstanding.
    expect($gift->refresh()->isRedeemed())->toBeTrue()
        ->and($gift->redeemed_at)->not->toBeNull();
});

it('asks the querent for an address rather than inheriting the recipient\'s', function () {
    aGiftOf(['code' => SPENDABLE, 'recipient_email' => 'whoever-was-sent-it@example.com']);

    redeeming(['querent_email' => 'sam@example.com'])->assertOk();

    // A forwarded gift is enough to part the recipient from the querent, and
    // inheriting silently would deliver the reading to whoever forwarded it.
    expect(Reading::sole()->querent_email)->toBe('sam@example.com');
});

it('tells Jennifer a reading is waiting', function () {
    aGiftOf(['code' => SPENDABLE]);

    redeeming()->assertOk();

    // The same message settlement sends, because it is the same moment in
    // different clothes. A redemption sends its own rather than joining one,
    // since it happened on its own day.
    Mail::assertQueued(ReadingToWrite::class, 1);

    // ~~**The querent gets nothing yet.** Her own mail is B8 (#74), and until
    // it lands the delivery promise is made on a screen she can close.~~ B8
    // landed, and she gets one. What survives that correction is the half this
    // test is about: hers is a second message and not this one, so the count
    // above stays 1.
    Mail::assertQueued(ReadingOnItsWay::class, 1);

    // Neither of them leaves inside the request. Both wait for the commit, for
    // the reason `AskForReading` gives: a message sent inside a transaction
    // that then rolls back has still been sent.
    Mail::assertNothingSent();
});

it('tells the querent her reading is on its way', function () {
    aGiftOf(['code' => SPENDABLE, 'recipient_email' => 'whoever-was-sent-it@example.com']);

    redeeming(['querent_email' => 'sam@example.com'])->assertOk();

    // **To the querent and never to the recipient.** A forwarded gift is enough
    // to part those two people, and this is the message that carries the
    // delivery promise: sending it to the address the buyer typed would promise
    // a reading to somebody who is not getting one.
    Mail::assertQueued(
        ReadingOnItsWay::class,
        fn (ReadingOnItsWay $mail): bool => $mail->hasTo('sam@example.com')
            && ! $mail->hasTo('whoever-was-sent-it@example.com'),
    );
});

it('takes an optional name, exactly as a buyer\'s is', function () {
    aGiftOf(['code' => SPENDABLE]);

    redeeming(['querent_name' => null])->assertOk();

    expect(Reading::sole()->querent_name)->toBeNull();
});

it('refuses a code that has already been spent', function () {
    aGiftOf(['code' => SPENDABLE]);

    redeeming()->assertOk();

    // **409 rather than 404**, because the difference between "you mistyped it"
    // and "this has already been used" is exactly what the real recipient needs
    // to be told. It carries a 422's body so existing error handling reads it
    // unchanged, and it is not a 422 because there is nothing here to fix.
    redeeming(['question' => 'Ask it twice?'])
        ->assertStatus(409)
        ->assertJsonValidationErrors('code');

    expect(Reading::count())->toBe(1);
});

it('answers 404 for a code nobody was given', function () {
    aGiftOf(['code' => SPENDABLE]);

    redeeming(['code' => 'ZZZZ-ZZZZ-ZZZZ'])
        ->assertNotFound()
        ->assertJsonPath('message', fn (mixed $message): bool => is_string($message) && $message !== '');

    expect(Reading::count())->toBe(0);
});

it('cannot spend a present whose money never arrived', function () {
    aGiftOf(['code' => null]);

    redeeming(['code' => '----'])->assertNotFound();

    expect(Reading::count())->toBe(0);
});

it('reads a typed code the way the lookup does', function () {
    aGiftOf(['code' => '1234567890AB']);

    // One definition of what somebody typed, so a code that resolves on the
    // page cannot fail to resolve on submit.
    redeeming(['code' => 'i234 5678-9oab'])->assertOk();

    expect(Gift::sole()->isRedeemed())->toBeTrue();
});

it('redeems a code for a reading nobody can buy any more', function () {
    aGiftOf(['code' => SPENDABLE], ['is_published' => false, 'name' => ['en' => 'MONTH AHEAD']]);

    // **The one place in this system that reads a product past
    // `is_published`**, on this endpoint as on the lookup. The person paid.
    redeeming()->assertOk()->assertJsonPath('name', 'MONTH AHEAD');

    expect(Reading::count())->toBe(1);
});

it('leaves the order owing, because a reading is now outstanding', function () {
    $gift = aGiftOf(['code' => SPENDABLE]);

    redeeming()->assertOk();

    // Redemption creates work rather than finishing it. `MarkOrderFulfilled`
    // gains its caller when the reading is delivered, on the Readings screen,
    // and not here.
    expect($gift->orderLine->order->refresh()->everythingItOwesHasGone())->toBeFalse()
        ->and($gift->orderLine->order->fulfilled_at)->toBeNull();
});

it('refuses a redemption with nothing to ask', function () {
    aGiftOf(['code' => SPENDABLE]);

    // Every giftable product allows a question, this is the moment the reading
    // is asked for, and a reading without a question is not one. Unlike a
    // purchase, where the question is optional because the buyer may be buying
    // for somebody who will ask later.
    $this->postJson('/api/v1/en/gifts/redeem', [
        'code' => SPENDABLE,
        'querent_email' => 'sam@example.com',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['question']);

    expect(Gift::sole()->isRedeemed())->toBeFalse();
});

it('refuses a redemption with nowhere to send the reading', function () {
    aGiftOf(['code' => SPENDABLE]);

    $this->postJson('/api/v1/en/gifts/redeem', [
        'code' => SPENDABLE,
        'question' => 'What should I focus on this month?',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['querent_email']);

    redeeming(['querent_email' => 'not-an-address'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['querent_email']);

    expect(Gift::sole()->isRedeemed())->toBeFalse();
});

it('spends nothing when the asking fails', function () {
    aGiftOf(['code' => SPENDABLE]);

    // **The stamp and the reading are one transaction**, so a failure between
    // them cannot leave a present marked spent with nothing asked for. There
    // are no refunds and no expiry, so that state is unrecoverable by hand.
    app()->instance(AskForReading::class, new class extends AskForReading
    {
        public function forGift(Gift $gift, string $question, string $querentEmail, ?string $querentName = null): Reading
        {
            throw new RuntimeException('the asking failed');
        }
    });

    // The action rather than the endpoint, because the exception handler turns
    // anything thrown in a request into a 500 and the question here is what the
    // transaction did, not what the customer saw.
    expect(fn () => app(RedeemGift::class)(Gift::sole(), 'What now?', 'sam@example.com'))
        ->toThrow(RuntimeException::class);

    expect(Gift::sole()->isRedeemed())->toBeFalse()
        ->and(Reading::count())->toBe(0);

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('sends nothing until the redemption has actually committed', function () {
    aGiftOf(['code' => SPENDABLE]);

    // A message sent inside a transaction that then rolls back has still been
    // sent, and no amount of rolling back unsends it. `AskForReading` waits for
    // the commit, and this is the only arrangement that can tell that apart
    // from sending inside it.
    DB::transaction(function (): void {
        redeeming()->assertOk();

        DB::rollBack();
    });

    Mail::assertNothingSent();
    Mail::assertNothingQueued();

    expect(Gift::sole()->isRedeemed())->toBeFalse()
        ->and(Reading::count())->toBe(0);
});

it('lets nothing keep the confirmation', function () {
    aGiftOf(['code' => SPENDABLE]);

    redeeming()->assertHeader('Cache-Control', 'no-store, private');
});

/**
 * Looser than the lookup, per the note in `routes/api.php`. A person fighting
 * with a validation error must not be locked out of the present they were
 * given, and the guessing budget it appears to buy is not one: what closes that
 * oracle is the entropy in the code rather than either number.
 */
it('refuses somebody working through codes here too', function () {
    foreach (range(1, 20) as $attempt) {
        redeeming(['code' => "ZZZZZZZZZZ{$attempt}"])->assertNotFound();
    }

    redeeming(['code' => 'ZZZZ-ZZZZ-ZZZZ'])->assertStatus(429)->assertHeader('Retry-After');
});

/**
 * The limiter's key prefix is load bearing, per the comment at the top of
 * `routes/api.php`.
 */
it('spends its own rate limit rather than the lookup endpoint\'s', function () {
    aGiftOf(['code' => SPENDABLE]);

    foreach (range(1, 20) as $attempt) {
        redeeming(['code' => "ZZZZZZZZZZ{$attempt}"])->assertNotFound();
    }

    redeeming(['code' => 'ZZZZ-ZZZZ-ZZZZ'])->assertStatus(429);

    expect(test()->postJson('/api/v1/en/gifts/lookup', ['code' => SPENDABLE])->status())->toBe(200);
});
