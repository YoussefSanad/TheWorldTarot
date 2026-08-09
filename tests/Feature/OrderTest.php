<?php

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('belongs to a customer and holds its lines', function () {
    $customer = Customer::factory()->create();
    $product = Product::factory()->create();

    $order = Order::factory()->for($customer)->forProduct($product)->create();

    expect($order->customer->is($customer))->toBeTrue()
        ->and($order->lines)->toHaveCount(1)
        ->and($order->lines->first()->product->is($product))->toBeTrue();
});

it('cannot exist without a customer', function () {
    // Every order belongs to somebody. A buyer with no account gets one made
    // from the email they gave, so there is no such thing as an ownerless order.
    expect(fn () => Order::factory()->create(['customer_id' => null]))
        ->toThrow(QueryException::class);
});

it('will not let a customer be deleted out from under an order', function () {
    $customer = Customer::factory()->create();
    Order::factory()->for($customer)->create();

    // Restricted rather than cascading. Destroying the record of a payment
    // because somebody tidied a customer row is not a tidy-up.
    expect(fn () => $customer->delete())->toThrow(QueryException::class);
});

it('keeps every amount as a whole number of minor units', function () {
    $order = Order::factory()->forProduct(Product::factory()->create())->create();

    $stored = DB::table('orders')->where('id', $order->id)->first();
    $line = DB::table('order_lines')->where('order_id', $order->id)->first();

    // Hard rule 5. Checked against the database rather than the model, since a
    // cast would make a float look like an integer on the way back out.
    expect($stored->total_amount)->toBeInt()
        ->and($line->unit_amount)->toBeInt()
        ->and($line->quantity)->toBeInt();
});

it('adds up to the sum of its lines', function () {
    $order = Order::factory()->forProduct(Product::factory()->create(['price_usd' => 2500]), 3)->create();

    expect($order->fresh()->total_amount)->toBe(7500)
        ->and($order->fresh()->load('lines')->totalOfLines())->toBe(7500);
});

it('remembers what it charged after the price changes', function () {
    $product = Product::factory()->create(['price_usd' => 2500]);
    $order = Order::factory()->forProduct($product)->create();

    // Jennifer puts her prices up. This is the whole reason prices are captured
    // onto the line rather than joined from the catalogue: an order that
    // recalculates itself reports a total nobody was ever charged.
    $product->update(['price_usd' => 4000]);

    expect($order->fresh()->total_amount)->toBe(2500)
        ->and($order->fresh()->lines->first()->unit_amount)->toBe(2500);
});

it('still knows what was bought after the product is retired', function () {
    $product = Product::factory()->create();
    $order = Order::factory()->forProduct($product)->create();

    $product->delete();

    // Products soft delete precisely so this keeps working. Without withTrashed
    // on the relationship, an old order in the panel shows a purchase of
    // nothing.
    $line = $order->fresh()->lines->first();

    expect($line->product)->not->toBeNull()
        ->and($line->product->key)->toBe($product->key)
        ->and($line->product_key)->toBe($product->key);
});

it('throws its lines away with it', function () {
    $order = Order::factory()->forProduct(Product::factory()->create())->create();

    $order->delete();

    // A line has no meaning without its order.
    expect(OrderLine::where('order_id', $order->id)->exists())->toBeFalse();
});

it('starts pending, and knows when it was paid and when it was sent', function () {
    $pending = Order::factory()->create();
    $paid = Order::factory()->paid()->create();
    $sent = Order::factory()->fulfilled()->create();

    expect($pending->status)->toBe(OrderStatus::Pending)
        ->and($pending->isPaid())->toBeFalse()
        ->and($paid->isPaid())->toBeTrue()
        ->and($paid->paid_at)->not->toBeNull()
        // Paid and not yet written is the normal state of a manual reading, and
        // the reason completion is a date rather than another status.
        ->and($paid->isFulfilled())->toBeFalse()
        ->and($sent->isFulfilled())->toBeTrue();
});

it('lists what Jennifer still owes somebody', function () {
    Order::factory()->create();
    $awaiting = Order::factory()->paid()->create();
    Order::factory()->fulfilled()->create();
    Order::factory()->cancelled()->create();

    $working = Order::awaitingFulfillment()->get();

    expect($working)->toHaveCount(1)
        ->and($working->first()->is($awaiting))->toBeTrue();
});

it('carries a question on the line rather than the order', function () {
    $order = Order::factory()
        ->forProduct(Product::factory()->create(), 1, 'Will the move be right for me?')
        ->create();

    // On the order, an order holding two readings could only ask one question.
    expect($order->lines->first()->question)->toBe('Will the move be right for me?');
});

it('holds two different readings, each with its own question', function () {
    $order = Order::factory()->create();
    $first = Product::factory()->create();
    $second = Product::factory()->create();

    OrderLine::factory()->for($order)->create([
        'product_id' => $first->id,
        'product_key' => $first->key,
        'question' => 'The first question',
    ]);

    OrderLine::factory()->for($order)->create([
        'product_id' => $second->id,
        'product_key' => $second->key,
        'question' => 'The second question',
    ]);

    expect($order->load('lines')->lines->pluck('question')->all())
        ->toBe(['The first question', 'The second question']);
});
