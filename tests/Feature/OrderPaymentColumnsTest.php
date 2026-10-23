<?php

use App\Models\Order;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

/**
 * What a card payment leaves behind on an order.
 *
 * ~~A test here settled an order by hand and asserted nothing was written in
 * the payment columns.~~ Removed 22 October 2026: that road no longer exists.
 */
it('keeps the money columns nullable at the database level', function () {
    $columns = collect(DB::select(
        'select column_name, is_nullable, column_default from information_schema.columns
         where table_name = ? and column_name in (?, ?, ?)',
        ['orders', 'charged_amount', 'stripe_payment_intent_id', 'pay_token'],
    ))->keyBy('column_name');

    expect($columns)->toHaveCount(3);

    foreach ($columns as $column) {
        expect($column->is_nullable)->toBe('YES')
            ->and($column->column_default)->toBeNull();
    }
});

it('records what was actually collected, in minor units', function () {
    $order = Order::factory()->create();

    $order->forceFill(['charged_amount' => 7500])->save();

    expect($order->fresh()->charged_amount)->toBe(7500);
});

/*
| The pay token, which is how a guest proves an order is theirs.
*/

it('gives every order a token, however the order was made', function () {
    $placed = Order::factory()->create();

    expect($placed->pay_token)->toBeString()
        ->and(strlen($placed->pay_token))->toBeGreaterThanOrEqual(32);
});

it('mints a token nobody can derive from the order', function () {
    // Sequential ids are the reason this column exists, and the model says the
    // token is derived from nothing about the order. The way to hold it to
    // that is to make the same order twice: identical id, customer, status,
    // currency and total, the second minted after the first is gone. Any
    // function of the order's own data hands back the first token, and that
    // includes the hash somebody would reach for on the way to fixing a
    // guessable id.
    //
    // **This replaces a substring check**, `not->toContain((string) $order->id)`,
    // which read as if it said this and did not. A random forty eight character
    // token contains any given digit about half the time, so against a single
    // digit id it was a coin flip, and it only looked green because a full run
    // gives orders two digit ids. It failed six times in eight when run alone.
    $first = Order::factory()->create();

    $was = $first->pay_token;
    $sameOrder = collect($first->getAttributes())
        ->except(['pay_token', 'created_at', 'updated_at'])
        ->all();

    $first->delete();

    $again = Order::factory()->create($sameOrder);

    expect($again->id)->toBe($sameOrder['id'])
        ->and($again->pay_token)->not->toBe($was);
});

it('refuses two orders sharing a token', function () {
    $first = Order::factory()->create();

    expect(fn () => Order::factory()->create(['pay_token' => $first->pay_token]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('leaves a token alone once it exists, so a saved order keeps its address', function () {
    $order = Order::factory()->create();
    $token = $order->pay_token;

    $order->forceFill(['total_amount' => 1234])->save();

    expect($order->fresh()->pay_token)->toBe($token);
});
