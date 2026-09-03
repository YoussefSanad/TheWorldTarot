<?php

use App\Actions\MarkOrderPaid;
use App\Mail\ReadingToWrite;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Reading;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

/*
| A reading is a row, and it exists from the moment somebody asks.
|
| `docs/adr/0004-a-reading-is-a-row-of-its-own.md`. Settlement and redemption are
| the same moment in different clothes, and this file covers the first of them.
| There is no gifting here on purpose: the shape is proved with nothing
| gift-shaped in it, so that a wrong shape is found with nothing confusing the
| diagnosis.
|
| **The seam is settlement, and what is observed is the row.** There is no other
| interface onto a reading yet, since the panel screen is B4 and the API never
| sees one. Reading the table back is the interface here rather than a side
| channel around it.
|
| `MarkOrderPaidTest` is the other half and is deliberately untouched. Every
| assertion in it holds unchanged, which is the gate on this unit.
*/

/**
 * Settle an order the way both roads into payment do.
 */
function settling(Order $order): bool
{
    return app(MarkOrderPaid::class)($order);
}

/**
 * An order for one reading, with the question its buyer typed at checkout.
 */
function anOrderAsking(string $question, ?Customer $customer = null): Order
{
    return Order::factory()
        ->for($customer ?? Customer::factory()->create())
        ->forProduct(Product::factory()->create(), 1, $question)
        ->create();
}

it('creates one reading when a reading order is settled', function () {
    $order = anOrderAsking('Will the move be right for me?');

    settling($order);

    expect(Reading::count())->toBe(1);
});

it('asks at the moment of settlement, not at the moment of buying', function () {
    $order = anOrderAsking('Will the move be right for me?');

    // Placed today, paid a fortnight later. `asked_at` is the clock the delivery
    // window runs from, so it is the settlement it has to come from and not the
    // checkout.
    $this->travel(2)->weeks();
    $this->freezeTime();

    settling($order);

    expect(Reading::sole()->asked_at->eq($order->refresh()->paid_at))->toBeTrue();
});

it('copies the question the buyer typed onto the reading', function () {
    settling(anOrderAsking('Will the move be right for me?'));

    // Captured, the way a line already captures `product_key` and
    // `unit_amount`, rather than joined for. `readings.question` is the question
    // of record from here on and `order_lines.question` is what somebody typed.
    expect(Reading::sole()->question)->toBe('Will the move be right for me?');
});

it('captures the querent rather than joining to them', function () {
    $buyer = Customer::factory()->create([
        'name' => 'Jodie',
        'email' => 'jodie@example.com',
    ]);

    settling(anOrderAsking('Will the move be right for me?', $buyer));

    // A gift's querent may have no customer row at all and deliberately never
    // gets one, so where a reading goes is held on the reading.
    $reading = Reading::sole();

    expect($reading->querent_email)->toBe('jodie@example.com')
        ->and($reading->querent_name)->toBe('Jodie');
});

it('holds the line that was bought, at the price it was that day', function () {
    $order = anOrderAsking('Will the move be right for me?');

    settling($order);

    expect(Reading::sole()->order_line_id)->toBe($order->lines->sole()->id);
});

it('came from nowhere, because nothing has been gifted', function () {
    settling(anOrderAsking('Will the move be right for me?'));

    // The column exists and nothing writes it until B5. Asserted rather than
    // assumed, because a null nobody checks is how a default quietly becomes a
    // value.
    expect(Reading::sole()->gift_id)->toBeNull();
});

it('has not been delivered, because nobody has written it yet', function () {
    settling(anOrderAsking('Will the move be right for me?'));

    expect(Reading::sole()->delivered_at)->toBeNull();
});

it('creates nothing for a pass, which nobody asked a question of', function () {
    $order = Order::factory()
        ->forProduct(Product::factory()->pass()->create())
        ->create();

    settling($order);

    // A reading nobody asked is not a reading. The pass is delivered in the
    // same instant the money lands and there is nothing to write.
    expect(Reading::count())->toBe(0);
});

it('creates one reading for each reading on an order', function () {
    $order = Order::factory()->create();

    foreach (['Will the move be right for me?', 'And what of the work?'] as $question) {
        $product = Product::factory()->create();

        $order->lines()->create([
            'product_id' => $product->id,
            'product_key' => $product->key,
            'unit_amount' => $product->price_usd,
            'quantity' => 1,
            'question' => $question,
        ]);
    }

    settling($order);

    expect(Reading::pluck('question')->all())
        ->toBe(['Will the move be right for me?', 'And what of the work?']);
});

it('creates a reading for the reading on an order that also holds a pass', function () {
    $order = Order::factory()->create();
    $pass = Product::factory()->pass()->create();
    $reading = Product::factory()->create();

    foreach ([$pass, $reading] as $product) {
        $order->lines()->create([
            'product_id' => $product->id,
            'product_key' => $product->key,
            'unit_amount' => $product->price_usd,
            'quantity' => 1,
            'question' => $product->is($reading) ? 'Will the move be right for me?' : null,
        ]);
    }

    settling($order);

    // Half delivered is not delivered, and the half that is owed is the one
    // that becomes a row.
    expect(Reading::sole()->order_line_id)
        ->toBe($order->lines->firstWhere('product_id', $reading->id)->id);
});

/*
| Running twice.
|
| Managed queues deliver at least once and a payment webhook is redelivered as a
| matter of routine. `MarkOrderPaid` survives that by structure rather than by
| discipline, and a second reading for a payment that arrived twice would be a
| second thing on Jennifer's list that nobody asked for.
*/

it('creates one reading and not two when a payment is redelivered', function () {
    $order = anOrderAsking('Will the move be right for me?');

    expect(settling($order))->toBeTrue()
        ->and(settling($order))->toBeFalse()
        ->and(settling($order))->toBeFalse();

    expect(Reading::count())->toBe(1);
});

it('creates nothing for an order that was never pending', function () {
    $order = Order::factory()->cancelled()->forProduct(Product::factory()->create())->create();

    expect(settling($order))->toBeFalse()
        ->and(Reading::count())->toBe(0);
});

/*
| One mail per asking, not one per reading.
|
| The trap this unit exists to avoid. `ReadingToWrite`'s reason survives the move
| out of `MarkOrderPaid`: one message covers everything opened in the same
| instant, so Jennifer gets a single thing to work from rather than three that
| arrive together and have to be reassembled.
|
| B6 adds a second caller at redemption, which is its own day and so its own
| mail. That is why this is asserted here rather than left to the count in
| `MarkOrderPaidTest`, which cannot tell one order from one asking.
*/

it('tells Jennifer once about two readings settled in the same instant', function () {
    $order = Order::factory()->create();

    foreach (['Will the move be right for me?', 'And what of the work?'] as $question) {
        $product = Product::factory()->create();

        $order->lines()->create([
            'product_id' => $product->id,
            'product_key' => $product->key,
            'unit_amount' => $product->price_usd,
            'quantity' => 1,
            'question' => $question,
        ]);
    }

    settling($order);

    expect(Reading::count())->toBe(2);

    Mail::assertQueued(ReadingToWrite::class, 1);
});

it('queues the telling rather than sending it while the order is being settled', function () {
    settling(anOrderAsking('Will the move be right for me?'));

    // A message sent inside a transaction that then rolls back has still been
    // sent, and no amount of rolling back unsends it.
    Mail::assertNothingSent();
    Mail::assertQueued(ReadingToWrite::class);
});

/*
| A reading nobody typed a question for.
|
| **ADR 0004 said `question` NOT NULL and this is the case that reversed it**,
| struck and dated in the migration. `StoreOrderRequest` has always accepted a
| line with no question, `Rules` only refuses a question on a product that does
| not allow one, and the fulfillment mail has always had a branch reading "No
| question was asked." A reachable purchase cannot be a row the database
| refuses, and refusing it at settlement means throwing after the money is
| taken.
|
| Whether that purchase should be possible at all is a different question,
| belongs to whoever owns the contract, and is recorded on #69.
*/

it('creates a reading for a reading nobody typed a question for', function () {
    $order = Order::factory()
        ->forProduct(Product::factory()->create())
        ->create();

    settling($order);

    expect(Reading::count())->toBe(1)
        ->and(Reading::sole()->question)->toBeNull();
});
