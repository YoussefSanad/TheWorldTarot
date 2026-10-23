<?php

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Stripe\Exception\ApiConnectionException;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;

uses(RefreshDatabase::class);

/**
 * Reading a payment's status back, for the confirmation screen.
 *
 * **The substituted client agrees with this code whatever this code does**, so
 * nothing in this file is evidence that Stripe returns any of these statuses or
 * accepts a retrieve of this shape. What it pins is the part that is ours:
 * which orders are asked about, which are answered without asking, what is
 * passed through, and that nothing is ever written.
 *
 * See .claude/external-services.md.
 */

/**
 * A Stripe that answers about the intents named and fails loudly about any
 * other. Asking about an intent no test set up is a defect here rather than a
 * case to handle quietly.
 *
 * @param  array<string, array<string, mixed>|Throwable>  $intents
 * @param  array<int, string>|null  $asked  Filled with every id retrieved.
 */
function stripeAnsweringAbout(array $intents, ?array &$asked = null): void
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
 * A Stripe nobody is allowed to ask. Any retrieve at all fails the test, which
 * is how "answered without a round trip" is proved rather than asserted.
 */
function stripeNobodyMayAsk(): void
{
    $service = Mockery::mock(PaymentIntentService::class);
    $service->shouldReceive('retrieve')->never();

    substitutedStripe($service);
}

function orderAwaitingConfirmation(?Customer $customer = null): Order
{
    return Order::factory()
        ->for($customer ?? Customer::factory()->create())
        ->forProduct(Product::factory()->create(['price_usd' => 7500]))
        ->create();
}

function orderWithIntent(string $intentId, ?Customer $customer = null): Order
{
    $order = orderAwaitingConfirmation($customer);

    $order->forceFill(['stripe_payment_intent_id' => $intentId])->save();

    return $order->refresh();
}

/*
| What it answers.
*/

it('answers the payment intent status Stripe holds, passed through unchanged', function (string $status) {
    $order = orderWithIntent('pi_confirmation');

    stripeAnsweringAbout(['pi_confirmation' => ['status' => $status]], $asked);

    $this->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertOk()
        ->assertExactJson(['status' => $status]);

    expect($asked)->toBe(['pi_confirmation']);
})->with([
    'requires_payment_method',
    'requires_confirmation',
    'requires_action',
    'processing',
    'requires_capture',
    'canceled',
    'succeeded',
]);

/**
 * The set can grow, and a status this application has never heard of is still
 * Stripe's answer about somebody's money. Passing it through is what lets the
 * frontend's "poll again" arm work without a deploy here.
 */
it('passes through a status it has never heard of rather than translating it', function () {
    $order = orderWithIntent('pi_new_status');

    stripeAnsweringAbout(['pi_new_status' => ['status' => 'requires_something_invented_later']]);

    $this->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertOk()
        ->assertExactJson(['status' => 'requires_something_invented_later']);
});

/**
 * **An order with no payment started answers rather than erroring**, and it
 * answers the thing the confirmation screen already does nothing with: no money
 * has been collected. See section 2 of API_CONTRACT.md.
 */
it('answers requires payment method for an order no payment was started for', function () {
    $order = orderAwaitingConfirmation();

    stripeNobodyMayAsk();

    $this->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertOk()
        ->assertExactJson(['status' => 'requires_payment_method']);
});

/**
 * **One path on the confirmation screen rather than two.** ~~An order settled in
 * the panel has no Stripe payment behind it at all~~ (struck through 22 October
 * 2026: the panel can no longer settle an order, though a paid order with no
 * intent is still answered from the order). A gift code redemption will have no
 * Stripe payment behind it either, so the answer comes from the order rather
 * than from an intent that does not exist.
 */
it('answers succeeded for an order settled by hand, with no payment at Stripe', function () {
    $order = orderAwaitingConfirmation();
    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    stripeNobodyMayAsk();

    $this->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertOk()
        ->assertExactJson(['status' => 'succeeded']);
});

it('answers succeeded for a paid order without asking Stripe again', function () {
    $order = orderWithIntent('pi_already_settled');
    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    stripeNobodyMayAsk();

    $this->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertOk()
        ->assertExactJson(['status' => 'succeeded']);
});

/*
| Reading only.
*/

/**
 * **The webhook settles orders and this does not**, even when it is looking
 * straight at a payment that succeeded. Two writers for one transition is how
 * fulfillment comes to differ between them, and a polled endpoint is the worst
 * possible second one: it runs on whatever interval a browser felt like.
 */
it('never settles an order, even when Stripe says the payment succeeded', function () {
    $order = orderWithIntent('pi_succeeded_unsettled');

    stripeAnsweringAbout(['pi_succeeded_unsettled' => [
        'status' => 'succeeded',
        'amount' => 7500,
        'amount_received' => 7500,
        'currency' => 'usd',
        'metadata' => ['order_id' => (string) $order->id],
    ]]);

    $this->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertOk()
        ->assertExactJson(['status' => 'succeeded']);

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->paid_at)->toBeNull()
        ->and($order->fulfilled_at)->toBeNull();
});

/*
| Not being able to reach somebody else's order.
*/

it('does not know an unknown token', function () {
    orderWithIntent('pi_somebody_elses');

    stripeNobodyMayAsk();

    $this->postJson('/api/v1/orders/status', ['pay_token' => Order::mintPayToken()])
        ->assertNotFound();
});

/**
 * A malformed token and an unknown one answer identically, because the
 * difference between them is information about whether an order exists.
 */
it('answers a malformed token exactly as it answers an unknown one', function (string $token) {
    orderWithIntent('pi_untouched');

    stripeNobodyMayAsk();

    $unknown = $this->postJson('/api/v1/orders/status', ['pay_token' => Order::mintPayToken()])
        ->assertNotFound();

    // The status and what it says, which is everything a caller can see. The
    // stack trace beside it is debug output that exists in no deployed
    // environment, and its frames name the line this test called from.
    $this->postJson('/api/v1/orders/status', ['pay_token' => $token])
        ->assertNotFound()
        ->assertJsonPath('message', $unknown->json('message'));
})->with([
    'far too short' => 'x',
    'punctuation nobody mints' => '../../admin',
    'a whole URL' => 'https://evil.example.com/',
]);

it('refuses a signed in customer somebody else"s order', function () {
    $order = orderWithIntent('pi_not_yours');
    $somebodyElse = Customer::factory()->create();

    stripeNobodyMayAsk();

    $this->actingAs($somebodyElse, 'sanctum')
        ->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertNotFound();
});

/**
 * **An order belonging to nobody is not somebody else's.** A hosted checkout
 * leaves `customer_id` null until the webhook learns who paid, which is exactly
 * the window the confirmation screen polls in, so a customer who signed in
 * mid-checkout must not be refused their own order.
 */
it('lets a signed in customer read an order that is waiting for its customer', function () {
    $order = orderWithIntent('pi_unowned');
    $order->forceFill(['customer_id' => null])->save();

    stripeAnsweringAbout(['pi_unowned' => ['status' => 'processing']]);

    $this->actingAs(Customer::factory()->create(), 'sanctum')
        ->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertOk()
        ->assertExactJson(['status' => 'processing']);
});

it('lets the customer who placed it read it while signed in', function () {
    $customer = Customer::factory()->create();
    $order = orderWithIntent('pi_mine', $customer);

    stripeAnsweringAbout(['pi_mine' => ['status' => 'processing']]);

    $this->actingAs($customer, 'sanctum')
        ->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertOk()
        ->assertExactJson(['status' => 'processing']);
});

it('lets a guest holding the token read it, because that is the normal case', function () {
    $order = orderWithIntent('pi_guest');

    stripeAnsweringAbout(['pi_guest' => ['status' => 'succeeded']]);

    $this->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertOk();
});

it('tells a guesser nothing about the order behind a token', function () {
    $order = orderWithIntent('pi_quiet');

    stripeAnsweringAbout(['pi_quiet' => ['status' => 'succeeded']]);

    $body = $this->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertOk()
        ->json();

    // Not the amount, not the email, not the id, and not a fulfilment.
    expect(array_keys($body))->toBe(['status']);
});

/*
| The token is a credential, so where it travels is part of the endpoint.
*/

/**
 * **Not a `GET`, and not a path segment**, which is the whole reason this
 * endpoint has the shape it does. A token in a path lands in every access log
 * between the browser and this application.
 */
it('takes the token in the body, so it never reaches a URL', function () {
    $route = Route::getRoutes()->getByName('orders.status');

    expect($route->uri())->toBe('api/v1/orders/status')
        ->and($route->parameterNames())->toBe([])
        ->and($route->methods())->toContain('POST')
        ->and($route->methods())->not->toContain('GET');
});

it('is not reachable by a GET carrying the token in the query string', function () {
    $order = orderWithIntent('pi_get');

    stripeNobodyMayAsk();

    $this->getJson('/api/v1/orders/status?pay_token='.$order->pay_token)
        ->assertMethodNotAllowed();
});

it('refuses to be cached, because the answer is about somebody"s money', function () {
    $order = orderWithIntent('pi_no_store');

    stripeAnsweringAbout(['pi_no_store' => ['status' => 'succeeded']]);

    $this->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
});

it('names the field when no token was sent at all', function () {
    stripeNobodyMayAsk();

    $this->postJson('/api/v1/orders/status', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('pay_token');
});

/*
| When Stripe cannot be asked.
*/

/**
 * **An outage is said rather than guessed at.** Answering `processing` would be
 * this application inventing a status about somebody's money, and the frontend
 * polls, so the honest answer is that we could not find out and it should ask
 * again.
 */
it('says it could not find out when Stripe cannot be reached', function () {
    $order = orderWithIntent('pi_unreachable');

    stripeAnsweringAbout([
        'pi_unreachable' => new ApiConnectionException('Could not connect to Stripe.'),
    ]);

    $this->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertStatus(503);
});

it('keeps the token out of the log when it writes one', function () {
    $order = orderWithIntent('pi_logged');

    // **Every line written, at any level, rather than the one call this code
    // happens to make today.** The point of this test is that a credential
    // cannot reach the log, and an assertion aimed at a named method stays
    // green when the leak arrives through a different one.
    $written = [];

    Log::listen(function (MessageLogged $entry) use (&$written): void {
        $written[] = $entry->message.json_encode($entry->context);
    });

    stripeAnsweringAbout([
        'pi_logged' => new ApiConnectionException('Could not connect to Stripe.'),
    ]);

    $this->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertStatus(503);

    // It did write, so this is not passing by logging nothing at all.
    expect($written)->not->toBeEmpty();

    foreach ($written as $line) {
        expect($line)->not->toContain($order->pay_token);
    }
});

/**
 * An outage is worth one line, not one line per poll. A confirmation screen
 * asking every second would otherwise bury the record of the outage under
 * sixty identical copies a minute for every customer waiting on one.
 */
it('logs an outage once a minute rather than once a poll', function () {
    $order = orderWithIntent('pi_noisy');

    $written = 0;

    Log::listen(function () use (&$written): void {
        $written++;
    });

    stripeAnsweringAbout([
        'pi_noisy' => new ApiConnectionException('Could not connect to Stripe.'),
    ]);

    foreach (range(1, 5) as $ignored) {
        $this->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
            ->assertStatus(503);
    }

    expect($written)->toBe(1);

    // And the next minute says so again, because an outage that is still
    // happening is still worth knowing about.
    $this->travel(2)->minutes();

    $this->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertStatus(503);

    expect($written)->toBe(2);
});

/*
| Plumbing.
*/

/**
 * Its own throttle key prefix, per the rule at the top of routes/api.php.
 * Sharing a bucket is not theoretical: it was found on staging on 13 August
 * 2026, and a confirmation screen polls, so this endpoint would be the one
 * spending everybody else's allowance.
 */
it('counts its own requests rather than sharing a bucket', function () {
    $middleware = collect(Route::getRoutes()->getByName('orders.status')->gatherMiddleware())
        ->first(fn (string $m): bool => str_starts_with($m, 'throttle:'));

    expect($middleware)->toBeString();

    [, $arguments] = explode(':', $middleware, 2);

    expect(explode(',', $arguments))->toHaveCount(3)
        ->and(explode(',', $arguments)[2])->toBe('orders-status');
});

it('carries no locale segment, since a payment is not content', function () {
    $order = orderWithIntent('pi_locale');

    stripeNobodyMayAsk();

    $this->postJson('/api/v1/en/orders/status', ['pay_token' => $order->pay_token])
        ->assertNotFound();
});
