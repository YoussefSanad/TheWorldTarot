<?php

use App\Enums\OrderStatus;
use App\Mail\OrderPaid;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Stripe\Exception\ApiConnectionException;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;

uses(RefreshDatabase::class);

/**
 * The sweep that catches a payment nobody told us about.
 *
 * **The substituted client agrees with this code whatever this code does**, so
 * nothing here says Stripe accepts or returns any of it. What it pins is the
 * decisions that are ours: which orders are asked about, which are left alone,
 * and what happens to each answer.
 */
beforeEach(function () {
    Mail::fake();
});

/**
 * A Stripe that answers about the intents named, and fails loudly about any
 * other. Asking about an intent no test set up is a bug in the sweep, not a
 * case to handle quietly.
 *
 * @param  array<string, array<string, mixed>>  $intents
 * @param  array<int, string>  $asked  Filled with every id retrieved, in order.
 */
function stripeHolding(array $intents, ?array &$asked = null): void
{
    $asked ??= [];

    $service = Mockery::mock(PaymentIntentService::class);

    $service->shouldReceive('retrieve')->andReturnUsing(
        function (string $id) use ($intents, &$asked): PaymentIntent {
            $asked[] = $id;

            if (($intents[$id] ?? null) instanceof Throwable) {
                throw $intents[$id];
            }

            return PaymentIntent::constructFrom(array_merge(
                ['id' => $id, 'object' => 'payment_intent'],
                $intents[$id] ?? [],
            ));
        },
    );

    substitutedStripe($service);
}

/**
 * An order that has been waiting long enough for the sweep to care about it.
 */
function awaitingPayment(string $intentId, int $amount = 7500, string $currency = 'USD', int $minutesAgo = 20): Order
{
    $order = Order::factory()
        ->in($currency)
        ->forProduct(Product::factory()->create([
            'price_usd' => $amount,
            'price_eur' => $amount,
            'price_gbp' => $amount,
        ]))
        ->create(['created_at' => now()->subMinutes($minutesAgo)]);

    $order->forceFill(['stripe_payment_intent_id' => $intentId])->save();

    return $order->refresh();
}

/**
 * @return array<string, mixed>
 */
function succeededIntent(Order $order, ?int $amount = null, ?string $currency = null): array
{
    return [
        'amount' => $amount ?? $order->total_amount,
        'amount_received' => $amount ?? $order->total_amount,
        'currency' => strtolower($currency ?? $order->currency),
        'status' => 'succeeded',
        'metadata' => ['order_id' => (string) $order->id],
    ];
}

it('settles an order paid at stripe whose notification never arrived', function () {
    $order = awaitingPayment('pi_lost');
    stripeHolding(['pi_lost' => succeededIntent($order)]);

    // The failure this exists for. Stripe took the money, the webhook was lost
    // in transit, and without this the customer has paid and received nothing
    // until somebody notices.
    $this->artisan('orders:reconcile')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($order->fresh()->charged_amount)->toBe(7500);
});

/**
 * **Fifteen minutes, because the webhook is the fast path and this is the safe
 * one.** A payment that succeeded seconds ago has a notification in flight, and
 * asking Stripe about it races that notification for no benefit: both roads end
 * at MarkOrderPaid, so the only thing gained is an API call per order per tick
 * for every checkout in progress.
 */
it('leaves an order alone that has only just started paying', function () {
    $asked = [];
    $order = awaitingPayment('pi_fresh', minutesAgo: 2);
    stripeHolding(['pi_fresh' => succeededIntent($order)], $asked);

    $this->artisan('orders:reconcile')->assertSuccessful();

    expect($asked)->toBe([])
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending);
});

/**
 * **Driven by our own table, never by asking Stripe what it has.** An order
 * with no intent id had no payment started against it, so there is nothing at
 * Stripe to ask about and no id to ask with. Listing or searching Stripe to
 * find one would make the sweep's cost grow with Stripe's history rather than
 * with our own pending orders, which is a number in the low tens.
 */
it('ignores an order that never started paying', function () {
    $asked = [];
    $order = awaitingPayment('pi_unused');
    $order->forceFill(['stripe_payment_intent_id' => null])->save();
    stripeHolding([], $asked);

    $this->artisan('orders:reconcile')->assertSuccessful();

    expect($asked)->toBe([])
        ->and($order->fresh()->status)->toBe(OrderStatus::Pending);
});

/**
 * **Left pending, never cancelled**, per the no-expiry decision. A declined card
 * is a customer who comes back and tries another one, and an order this sweep
 * cancelled is a sale refused by a scheduled task on the customer's behalf.
 */
it('leaves an order pending when the card was refused', function () {
    $order = awaitingPayment('pi_declined');
    stripeHolding(['pi_declined' => [
        'amount' => $order->total_amount,
        'currency' => 'usd',
        'status' => 'requires_payment_method',
        'metadata' => ['order_id' => (string) $order->id],
    ]]);

    $this->artisan('orders:reconcile')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->fresh()->charged_amount)->toBeNull();
});

it('leaves an order pending when the payment was cancelled at stripe', function () {
    $order = awaitingPayment('pi_cancelled');
    stripeHolding(['pi_cancelled' => [
        'amount' => $order->total_amount,
        'currency' => 'usd',
        'status' => 'canceled',
        'metadata' => ['order_id' => (string) $order->id],
    ]]);

    $this->artisan('orders:reconcile')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

/**
 * The amount check belongs to RecordStripePayment and is proved there. What
 * this pins is that the sweep reaches it rather than going round it, because
 * two roads to being paid that disagree about what counts as paid is exactly
 * what that action exists to prevent.
 */
it('does not settle an order for an amount that disagrees with it', function () {
    $order = awaitingPayment('pi_wrong_amount');
    stripeHolding(['pi_wrong_amount' => succeededIntent($order, amount: 100)]);

    $this->artisan('orders:reconcile')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->fresh()->charged_amount)->toBeNull();
});

/**
 * **A paid order is not asked about**, which is what keeps the sweep bounded by
 * what is genuinely outstanding rather than by everything ever sold.
 */
it('never asks about an order that is already paid for', function () {
    $asked = [];
    $order = awaitingPayment('pi_done');
    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();
    stripeHolding(['pi_done' => succeededIntent($order)], $asked);

    $this->artisan('orders:reconcile')->assertSuccessful();

    expect($asked)->toBe([]);
});

/**
 * **Idempotent, and it comes for free.** MarkOrderPaid has locked the row and
 * refused a second transition since phase A, so the sweep needs no guard of its
 * own. The receipt is the assertion worth making: a customer who receives two
 * of them for one payment has a support conversation, and this is the first
 * caller ever to run twice over the same order as a matter of routine.
 */
it('sends one receipt however many times it sweeps', function () {
    $order = awaitingPayment('pi_twice');
    stripeHolding(['pi_twice' => succeededIntent($order)]);

    $this->artisan('orders:reconcile')->assertSuccessful();
    $this->artisan('orders:reconcile')->assertSuccessful();

    expect($order->fresh()->status)->toBe(OrderStatus::Paid);
    Mail::assertQueued(OrderPaid::class, 1);
});

/**
 * **One unreachable payment must not cost every other customer their order.**
 * The sweep runs unattended against a third party over a network, so a timeout,
 * a rate limit, or an id belonging to a Stripe account this environment no
 * longer holds keys for are all ordinary. Letting one of them end the run means
 * the orders after it in the list are swept only if the next tick happens to
 * find Stripe healthier.
 */
it('carries on when one payment cannot be asked about', function () {
    $unreachable = awaitingPayment('pi_unreachable');
    $fine = awaitingPayment('pi_fine');

    stripeHolding([
        'pi_unreachable' => ApiConnectionException::factory('Stripe could not be reached'),
        'pi_fine' => succeededIntent($fine),
    ]);

    $this->artisan('orders:reconcile')->assertSuccessful();

    expect($unreachable->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($fine->fresh()->status)->toBe(OrderStatus::Paid);
});

/**
 * The ordinary case, on almost every tick. A sweep that wakes a third party to
 * ask about payments nobody is waiting on is a sweep that costs money and rate
 * limit for nothing.
 */
it('asks nothing when no order is waiting', function () {
    $asked = [];
    Order::factory()->paid()->create();
    stripeHolding([], $asked);

    $this->artisan('orders:reconcile')
        ->expectsOutputToContain('No order is waiting')
        ->assertSuccessful();

    expect($asked)->toBe([]);
});

/**
 * Whoever is reading the log after a payment went missing needs to see which
 * orders were looked at, not a silent success.
 *
 * One substring per test, deliberately. Laravel matches each expected substring
 * against a single write, and Mockery satisfies only the first expectation a
 * given line matches, so two substrings that land on the same line quietly fail
 * the second one however right the output is.
 */
it('names each order it swept', function () {
    $settled = awaitingPayment('pi_reported');
    stripeHolding(['pi_reported' => succeededIntent($settled)]);

    $this->artisan('orders:reconcile')
        ->expectsOutputToContain('Order '.$settled->id)
        ->assertSuccessful();
});

it('says that a swept order was the one this sweep settled', function () {
    $settled = awaitingPayment('pi_reported');
    stripeHolding(['pi_reported' => succeededIntent($settled)]);

    $this->artisan('orders:reconcile')
        ->expectsOutputToContain('settled by this sweep')
        ->assertSuccessful();
});

it('says what stripe still thinks about an order it could not settle', function () {
    $order = awaitingPayment('pi_still_waiting');
    stripeHolding(['pi_still_waiting' => [
        'amount' => $order->total_amount,
        'currency' => 'usd',
        'status' => 'requires_payment_method',
        'metadata' => ['order_id' => (string) $order->id],
    ]]);

    $this->artisan('orders:reconcile')
        ->expectsOutputToContain('Still requires_payment_method')
        ->assertSuccessful();
});

/**
 * A cancelled order is an end rather than a pause. Asking Stripe about one
 * every tick forever is a question nobody will ever act on the answer to.
 */
it('never asks about an order that was cancelled', function () {
    $asked = [];
    $order = awaitingPayment('pi_abandoned');
    $order->forceFill(['status' => OrderStatus::Cancelled])->save();
    stripeHolding(['pi_abandoned' => succeededIntent($order)], $asked);

    $this->artisan('orders:reconcile')->assertSuccessful();

    expect($asked)->toBe([]);
});

/**
 * One of these is a network blip and every one of them every tick is an
 * integration that has stopped working. They look identical from the console
 * unless the sweep says which order it could not ask about.
 */
it('says which order stripe could not be asked about', function () {
    awaitingPayment('pi_silent');
    stripeHolding(['pi_silent' => ApiConnectionException::factory('Stripe could not be reached')]);

    $this->artisan('orders:reconcile')
        ->expectsOutputToContain('Stripe could not be asked about order')
        ->assertSuccessful();
});

/**
 * **A sweep nobody runs is a sweep that does not exist**, and it fails exactly
 * the way the whole of this file is written against: silently, with every other
 * test still green. The rest of this file proves the command settles a lost
 * payment. Only this proves anybody ever calls it.
 *
 * Every five minutes, so a lost notification costs a customer about twenty
 * minutes at worst: the fifteen minute window plus a tick. **This is a ceiling
 * and not a floor.** Nothing has ever measured how often the platform actually
 * runs `schedule:run`, so if it ticks hourly this is hourly whatever this line
 * says. See routes/console.php.
 */
it('is scheduled to run, without a second sweep beside it', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event): bool => str_contains($event->command ?? '', 'orders:reconcile'));

    expect($events)->toHaveCount(1);

    $event = $events->first();

    expect($event->expression)->toBe('*/5 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});

/**
 * **The sentence this file exists to stop the sweep printing.** A mismatch is
 * a settlement `RecordStripePayment` refused and wrote to `Log::error`, and the
 * console said `Still succeeded at Stripe`, which is what an operator reads as
 * a payment in flight. Nobody is watching the log, so the one place the fault
 * was visible said the opposite of what happened.
 *
 * The assertion is on the word rather than on the whole sentence, because any
 * rendering that puts Stripe's `succeeded` in front of somebody about an order
 * that was refused is the same defect wearing different words.
 */
it('never calls a refused settlement a payment still in flight', function () {
    $order = awaitingPayment('pi_wrong_amount');
    stripeHolding(['pi_wrong_amount' => succeededIntent($order, amount: 100)]);

    $this->artisan('orders:reconcile')
        ->doesntExpectOutputToContain('succeeded')
        ->assertSuccessful();
});

/**
 * What it says instead. A mismatch is a settlement this application turned
 * away, and the console is the only place an operator is looking.
 */
it('says an amount that disagrees was refused rather than left waiting', function () {
    $order = awaitingPayment('pi_wrong_amount');
    stripeHolding(['pi_wrong_amount' => succeededIntent($order, amount: 100)]);

    $this->artisan('orders:reconcile')
        ->expectsOutputToContain('Refused')
        ->assertSuccessful();
});

/**
 * **A fault is reported the way an unreachable Stripe already is**, and for the
 * same reason: the two-column detail is a status line somebody skims, and this
 * is a customer who has been charged and has not been served. It reached
 * `Log::error` and nowhere else, which is a channel nobody is watching.
 */
it('reports a refused settlement as an error and not as a status line', function () {
    $order = awaitingPayment('pi_wrong_amount');
    stripeHolding(['pi_wrong_amount' => succeededIntent($order, amount: 100)]);

    $this->artisan('orders:reconcile')
        ->expectsOutputToContain('Order '.$order->id.' was not settled')
        ->assertSuccessful();
});

/**
 * **The race, which is the rarest of the six and the one that reads worst.**
 * The sweep loads a pending order, a webhook settles it a moment later, and
 * `MarkOrderPaid` refuses the second transition. Nothing is wrong and nobody
 * needs to do anything, but the console used to answer `Still succeeded at
 * Stripe` about an order that had been paid for seconds earlier.
 *
 * Driven by settling the order inside the Stripe call, which is exactly where
 * the real race lands: after the query that selected it and before the
 * settlement that finds the work done.
 */
it('says an order was settled by something else rather than still waiting', function () {
    $order = awaitingPayment('pi_raced');

    $service = Mockery::mock(PaymentIntentService::class);
    $service->shouldReceive('retrieve')->andReturnUsing(
        function (string $id) use ($order): PaymentIntent {
            $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

            return PaymentIntent::constructFrom(array_merge(
                ['id' => $id, 'object' => 'payment_intent'],
                succeededIntent($order),
            ));
        },
    );

    substitutedStripe($service);

    $this->artisan('orders:reconcile')
        ->expectsOutputToContain('Already settled')
        ->assertSuccessful();
});
