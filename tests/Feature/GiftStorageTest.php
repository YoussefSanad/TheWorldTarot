<?php

use App\Models\Gift;
use App\Models\OrderLine;
use App\Models\Reading;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * The row a gifted line gets, and the constraints that are the database's
 * rather than the application's.
 *
 * **A gift is an outstanding obligation.** `docs/adr/0005-gift-codes-do-not-expire.md`
 * accepts that it never lapses, so `redeemed_at` being null is a liability that
 * stays on the books until somebody spends it. That is what makes the
 * constraints below worth proving directly: a duplicate code is two people
 * holding one present, and a second gift on one line is one payment owing two.
 */
it('refuses a second gift carrying the same code', function () {
    $first = Gift::factory()->create(['code' => 'ABCD1234WXYZ']);

    // **The database's refusal, caught, and never a check-then-insert.** Asking
    // whether a code exists and then writing it is two statements with a gap in
    // the middle, and a second request minting the same code slips through it.
    // The unique index is the only thing that cannot be raced, so the unique
    // index is what this asserts.
    // The line is made before the expectation, because Postgres aborts the
    // whole transaction the moment the constraint fires and `RefreshDatabase`
    // is one transaction. Nothing can be read afterwards, which is also why
    // this test ends on the throw rather than on a count.
    $line = OrderLine::factory()->create();

    expect(fn () => Gift::factory()->create([
        'order_line_id' => $line->id,
        'code' => $first->code,
    ]))->toThrow(QueryException::class);
});

it('refuses a second gift on one order line', function () {
    $gift = Gift::factory()->create();

    // One line is one thing bought once. A second gift hanging off it is one
    // payment owing two presents, and B6 mints inside a lock on the assumption
    // that this cannot happen.
    expect(fn () => Gift::factory()->create(['order_line_id' => $gift->order_line_id]))
        ->toThrow(QueryException::class);
});

it('refuses deleting the line a gift was bought on', function () {
    $gift = Gift::factory()->create();

    // Restricted, the same as `readings.order_line_id` and
    // `order_lines.product_id`: deleting the record of a purchase must fail
    // loudly rather than quietly orphan what was bought.
    expect(fn () => OrderLine::whereKey($gift->order_line_id)->delete())
        ->toThrow(QueryException::class);
});

it('lets a reading say which gift it came from', function () {
    $gift = Gift::factory()->create();

    $reading = Reading::factory()->create(['gift_id' => $gift->id]);

    // `readings.gift_id` landed in B3 nullable and unconstrained, because there
    // was no table to point it at. This is the unit that gives it one.
    expect($reading->fresh()->gift_id)->toBe($gift->id);
});

it('refuses a reading pointed at a gift that does not exist', function () {
    // The constraint the column went without between B3 and here.
    expect(fn () => Reading::factory()->create(['gift_id' => 99999]))
        ->toThrow(QueryException::class);
});

it('refuses deleting a gift a reading was made from', function () {
    $gift = Gift::factory()->create();
    Reading::factory()->create(['gift_id' => $gift->id]);

    // Restricted rather than nulled. A reading that forgot it was a present is
    // a reading nobody can explain the existence of.
    expect(fn () => Gift::whereKey($gift->id)->delete())->toThrow(QueryException::class);
});

it('starts life unredeemed, which is what makes it a liability', function () {
    $gift = Gift::factory()->create();

    // Null is an outstanding obligation, ADR 0005. It is not "unknown".
    expect($gift->redeemed_at)->toBeNull()
        ->and($gift->isRedeemed())->toBeFalse();
});

it('holds the four things the buyer typed', function () {
    $gift = Gift::factory()->create([
        'recipient_email' => 'sam@example.com',
        'signature' => 'Mum',
        'message' => 'Thought of you.',
        'locale' => 'es',
    ]);

    $row = DB::table('gifts')->where('id', $gift->id)->first();

    expect($row->recipient_email)->toBe('sam@example.com')
        ->and($row->signature)->toBe('Mum')
        ->and($row->message)->toBe('Thought of you.')

        // Which language the recipient's mail is written in, and the one field
        // ADR 0004's sketch of this table does not have. It is here because the
        // mail is rendered from it and the buyer chose it at checkout.
        ->and($row->locale)->toBe('es');
});

it('takes a gift with no message and no code yet', function () {
    // Both are nullable and for different reasons. A message is the buyer's
    // optional words. A code is null between placement and settlement, because
    // minting one before the money arrives leaves a live bearer credential
    // behind every abandoned checkout.
    $gift = Gift::factory()->create(['message' => null, 'code' => null]);

    expect($gift->fresh()->message)->toBeNull()
        ->and($gift->fresh()->code)->toBeNull();
});

it('lets two unminted gifts sit side by side', function () {
    // A unique index does not count nulls as equal, so "not minted yet" is not
    // a value two rows can collide on. Pinned, because the alternative shape,
    // an empty string, would collide on the second abandoned checkout.
    Gift::factory()->create(['code' => null]);
    Gift::factory()->create(['code' => null]);

    expect(Gift::whereNull('code')->count())->toBe(2);
});
