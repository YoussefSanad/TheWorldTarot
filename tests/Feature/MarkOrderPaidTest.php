<?php

use App\Actions\MarkOrderPaid;
use App\Enums\Fulfillment;
use App\Enums\OrderStatus;
use App\Mail\OrderPaid;
use App\Mail\ReadingToWrite;
use App\Mail\SetPasswordLink;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
});

function settle(Order $order): bool
{
    return app(MarkOrderPaid::class)($order);
}

function orderFor(Product $product, ?Customer $customer = null): Order
{
    return Order::factory()
        ->for($customer ?? Customer::factory()->create())
        ->forProduct($product)
        ->create();
}

it('marks a pending order paid and records when', function () {
    $order = orderFor(Product::factory()->create());

    expect(settle($order))->toBeTrue();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        // Paid is not sent. Jennifer still has to write the reading.
        ->and($order->fulfilled_at)->toBeNull();
});

it('grants the Viewing Room when the pass is what was bought', function () {
    $customer = Customer::factory()->create();

    expect($customer->hasViewingRoomAccess())->toBeFalse();

    settle(orderFor(Product::factory()->pass()->create(), $customer));

    expect($customer->fresh()->hasViewingRoomAccess())->toBeTrue();
});

it('counts a pass as fulfilled the moment it is paid', function () {
    $order = orderFor(Product::factory()->pass()->create());

    settle($order);

    // Nothing is owed. Access was granted in the same instant the money
    // landed, so leaving this waiting would put work on Jennifer's list that
    // cannot be done.
    expect($order->refresh()->isFulfilled())->toBeTrue()
        ->and(Order::awaitingFulfillment()->count())->toBe(0);
});

it('leaves a reading waiting, because somebody has to write it', function () {
    $order = orderFor(Product::factory()->create());

    settle($order);

    expect($order->refresh()->isFulfilled())->toBeFalse()
        ->and(Order::awaitingFulfillment()->count())->toBe(1);
});

it('waits for the reading when an order holds a pass and a reading', function () {
    $order = Order::factory()->create();
    $pass = Product::factory()->pass()->create();
    $reading = Product::factory()->create();

    foreach ([$pass, $reading] as $product) {
        $order->lines()->create([
            'product_id' => $product->id,
            'product_key' => $product->key,
            'unit_amount' => $product->price_usd,
            'quantity' => 1,
        ]);
    }

    settle($order);

    // Half delivered is not delivered. The pass is usable immediately and the
    // order is still owed a reading.
    expect($order->customer->fresh()->hasViewingRoomAccess())->toBeTrue()
        ->and($order->refresh()->isFulfilled())->toBeFalse();
});

it('grants nothing for a reading', function () {
    $customer = Customer::factory()->create();

    settle(orderFor(Product::factory()->create(), $customer));

    expect($customer->fresh()->hasViewingRoomAccess())->toBeFalse();
});

/*
| Running twice.
|
| It will. Managed queues deliver at least once and a payment webhook is
| redelivered as a matter of routine. Phase B's webhook does not solve this,
| this does, which is why it is proved here rather than there.
*/

it('does nothing at all the second time', function () {
    // Unclaimed on purpose, so every message this can send is in play and each
    // one has to be counted.
    $customer = Customer::factory()->create(['password' => null]);
    $order = orderFor(Product::factory()->pass()->create(), $customer);

    expect(settle($order))->toBeTrue()
        // False means it was already paid, which is a success and not a failure.
        ->and(settle($order))->toBeFalse()
        ->and(settle($order))->toBeFalse();

    Mail::assertQueued(OrderPaid::class, 1);
    Mail::assertQueued(SetPasswordLink::class, 1);
});

it('keeps the date somebody first got in, however many times it is settled', function () {
    $customer = Customer::factory()->create();
    $order = orderFor(Product::factory()->pass()->create(), $customer);

    settle($order);
    $firstTime = $customer->fresh()->viewing_room_access_at;

    $this->travel(2)->days();
    settle($order);

    // A duplicate settlement must not rewrite when access was granted.
    expect($customer->fresh()->viewing_room_access_at->eq($firstTime))->toBeTrue();
});

it('will not pay a cancelled order', function () {
    $order = Order::factory()->cancelled()->forProduct(Product::factory()->create())->create();

    expect(settle($order))->toBeFalse()
        ->and($order->fresh()->status)->toBe(OrderStatus::Cancelled);

    Mail::assertNothingQueued();
});

/*
| Who hears about it.
*/

it('confirms the order to the customer', function () {
    $customer = Customer::factory()->create(['email' => 'jennifer@example.com']);

    settle(orderFor(Product::factory()->create(), $customer));

    Mail::assertQueued(
        OrderPaid::class,
        fn (OrderPaid $mail): bool => $mail->hasTo('jennifer@example.com'),
    );
});

it('invites an unclaimed account to choose a password', function () {
    config()->set('frontend.url', 'https://staging.theworldtarot.com');

    $unclaimed = Customer::factory()->create(['password' => null]);

    settle(orderFor(Product::factory()->create(), $unclaimed));

    Mail::assertQueued(SetPasswordLink::class, function (SetPasswordLink $mail): bool {
        $rendered = $mail->render();

        // Its own page, not the reset page. "Reset your password" is confusing
        // wording for somebody who has never had one.
        return str_contains($rendered, 'https://staging.theworldtarot.com/set-password?')
            && str_contains($rendered, 'token=');
    });
});

it('never sends a claim link to an address that already has an account', function () {
    $claimed = Customer::factory()->create(['password' => 'a-long-enough-password']);

    settle(orderFor(Product::factory()->create(), $claimed));

    // Otherwise a stranger who knows your email can cause a set-a-password
    // message to land in your inbox. It hands them nothing, and it is still a
    // phishing shaped tool pointed at our own customers.
    Mail::assertNotQueued(SetPasswordLink::class);
    Mail::assertQueued(OrderPaid::class);
});

it('tells Jennifer a reading is waiting, with the question', function () {
    $order = Order::factory()
        ->forProduct(Product::factory()->create(), 1, 'Will the move be right for me?')
        ->create();

    settle($order);

    Mail::assertQueued(ReadingToWrite::class, function (ReadingToWrite $mail): bool {
        return $mail->hasTo(config('mail.notify.fulfillment'))
            && str_contains($mail->render(), 'Will the move be right for me?');
    });
});

it('does not tell Jennifer about a pass, which needs nothing written', function () {
    settle(orderFor(Product::factory()->pass()->create()));

    Mail::assertNotQueued(ReadingToWrite::class);
});

it('tells her once about an order holding two readings', function () {
    $order = Order::factory()->create();
    $first = Product::factory()->create();
    $second = Product::factory()->create();

    foreach ([$first, $second] as $product) {
        $order->lines()->create([
            'product_id' => $product->id,
            'product_key' => $product->key,
            'unit_amount' => $product->price_usd,
            'quantity' => 1,
        ]);
    }

    settle($order);

    // One thing to work from, rather than two arriving together that have to be
    // reassembled into one job.
    Mail::assertQueued(ReadingToWrite::class, 1);
});

it('queues every message rather than sending it while the order is being settled', function () {
    settle(orderFor(Product::factory()->pass()->create()));

    Mail::assertNothingSent();
    Mail::assertQueued(OrderPaid::class);
});

/*
| One question, asked once.
|
| "Does a person have to do something about this order" decides two things:
| whether the order is fulfilled the instant the money lands, and whether
| Jennifer is told there is a reading waiting. They were derived separately, a
| few lines apart, so nothing would have failed if they came to disagree.
|
| They are exact opposites, and this is what says so. An order fulfilled at
| payment that Jennifer is also told about is work on her list that is already
| done; an order left waiting that she never hears about is a customer who paid
| and never receives anything.
*/

it('either fulfills an order at payment or tells Jennifer about it, never both and never neither', function (string $state) {
    $order = anOrderHolding($state);

    settle($order);

    $told = Mail::queued(ReadingToWrite::class)->isNotEmpty();

    expect($order->refresh()->isFulfilled())->toBe(
        ! $told,
        "settling an order holding {$state} both fulfilled it and asked for it",
    );
})->with([
    'a pass',
    'a reading',
    'a pass and a reading',
    'two readings',
    'two things that need nobody',
]);

/**
 * One order in a named state, so a failure says which basket broke.
 */
function anOrderHolding(string $state): Order
{
    $products = match ($state) {
        'a pass' => [Product::factory()->pass()->create()],
        'a reading' => [Product::factory()->create()],
        'a pass and a reading' => [Product::factory()->pass()->create(), Product::factory()->create()],
        'two readings' => [Product::factory()->create(), Product::factory()->create()],
        'two things that need nobody' => [
            Product::factory()->pass()->create(),
            Product::factory()->addon()->create(['fulfillment' => Fulfillment::AccessGrant]),
        ],
    };

    $order = Order::factory()->create();

    foreach ($products as $product) {
        $order->lines()->create([
            'product_id' => $product->id,
            'product_key' => $product->key,
            'unit_amount' => $product->price_usd,
            'quantity' => 1,
        ]);
    }

    return $order;
}
