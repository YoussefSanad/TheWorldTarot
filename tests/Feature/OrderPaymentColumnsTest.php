<?php

use App\Actions\MarkOrderPaid;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

/**
 * What a card payment leaves behind on an order, and what a hand settled one
 * deliberately does not.
 */
it('settles an order by hand with nothing written in the payment columns', function () {
    $order = Order::factory()->forProduct(Product::factory()->create())->create();

    expect(app(MarkOrderPaid::class)($order))->toBeTrue();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        // **An order nobody was charged for must not look like one somebody
        // was.** A default of zero here would have every hand settled order
        // claiming a real charge of nothing.
        ->and($order->charged_amount)->toBeNull()
        ->and($order->stripe_payment_intent_id)->toBeNull();
});

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
    $order = Order::factory()->create();

    // Sequential ids are the reason this column exists. A token that contained
    // the id, or was any function of it, would put us back where we started.
    expect($order->pay_token)->not->toContain((string) $order->id);

    $mine = Order::factory()->create();
    $yours = Order::factory()->create();

    expect($mine->pay_token)->not->toBe($yours->pay_token);
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
