<?php

use App\Enums\OrderStatus;
use App\Jobs\SettleStripePayment;
use App\Mail\OrderPaid;
use App\Mail\PaymentDisputed;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Payments\StripeSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Stripe\Event;
use Stripe\Webhook;

uses(RefreshDatabase::class);

/**
 * The webhook, where most of the risk in this milestone lives.
 *
 * **What this file proves and what it does not.** Every signature below is
 * computed here, with the secret this environment is configured with, and
 * verified by `stripe-php`. That proves our plumbing: that the raw body reaches
 * the verifier unaltered, that the right header is read, that the configured
 * secret is used, that an old signature is refused and that a changed byte is
 * refused.
 *
 * **It does not prove that Stripe's signatures look like this.** Only Stripe
 * can say that, and `StripeWebhookVectorTest` is where it says it, from a
 * payload captured by hand. Reading this file as evidence about Stripe's format
 * would be the `BunnyStreamTest` mistake in new clothes: five days of 403s
 * behind a green suite.
 */
const SIGNING_SECRET = 'whsec_testsecretfortheworldtarot';

beforeEach(function () {
    Mail::fake();
    config()->set('services.stripe.webhook_secret', SIGNING_SECRET);
});

/**
 * The `Stripe-Signature` header for a body, as this test computes it.
 *
 * Only ever used to drive the endpoint. It is never asserted against, because
 * asserting our own signature back to ourselves would agree with any
 * implementation whatever it did.
 */
function signedWith(string $payload, ?int $timestamp = null, string $secret = SIGNING_SECRET): string
{
    $timestamp ??= time();

    return 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
}

function stripeEvent(string $type, array $object, string $id = 'evt_test_1'): string
{
    return json_encode([
        'id' => $id,
        'object' => 'event',
        'api_version' => '2025-07-30.basil',
        'created' => time(),
        'type' => $type,
        'data' => ['object' => $object],
    ], JSON_THROW_ON_ERROR);
}

function paymentIntent(array $overrides = []): array
{
    return array_merge([
        'id' => 'pi_test_1',
        'object' => 'payment_intent',
        'amount' => 7500,
        'amount_received' => 7500,
        'currency' => 'usd',
        'status' => 'succeeded',
        'metadata' => ['order_id' => '1'],
    ], $overrides);
}

/**
 * @return TestResponse
 */
function postToStripeWebhook(string $payload, ?string $signature = null)
{
    return test()->call(
        'POST',
        '/api/v1/webhooks/stripe',
        [], [], [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_STRIPE_SIGNATURE' => $signature ?? signedWith($payload),
        ],
        $payload,
    );
}

function orderCosting(int $amount, string $currency = 'USD', ?Customer $customer = null, bool $pass = false): Order
{
    $product = $pass
        ? Product::factory()->pass()->create(['price_usd' => $amount, 'price_eur' => $amount, 'price_gbp' => $amount])
        : Product::factory()->create(['price_usd' => $amount, 'price_eur' => $amount, 'price_gbp' => $amount]);

    return Order::factory()
        ->for($customer ?? Customer::factory()->create(['password' => bcrypt('already-claimed')]))
        ->in($currency)
        ->forProduct($product)
        ->create();
}

/*
| The success path, which is the only event that changes any state.
*/

it('marks an order paid and records what was actually collected', function () {
    $order = orderCosting(7500);

    $payload = stripeEvent('payment_intent.succeeded', paymentIntent([
        'id' => 'pi_real_1',
        'metadata' => ['order_id' => (string) $order->id],
    ]));

    postToStripeWebhook($payload)
        ->assertOk()
        ->assertExactJson(['received' => true]);

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_at)->not->toBeNull()
        ->and($order->charged_amount)->toBe(7500)
        ->and($order->stripe_payment_intent_id)->toBe('pi_real_1');
});

it('grants the pass in the same instant the money lands', function () {
    $customer = Customer::factory()->create(['password' => bcrypt('already-claimed')]);
    $order = orderCosting(7500, customer: $customer, pass: true);

    expect($customer->hasViewingRoomAccess())->toBeFalse();

    postToStripeWebhook(stripeEvent('payment_intent.succeeded', paymentIntent([
        'metadata' => ['order_id' => (string) $order->id],
    ])))->assertOk();

    expect($customer->fresh()->hasViewingRoomAccess())->toBeTrue();
});

it('sends exactly one receipt for one payment', function () {
    $order = orderCosting(7500);

    postToStripeWebhook(stripeEvent('payment_intent.succeeded', paymentIntent([
        'metadata' => ['order_id' => (string) $order->id],
    ])))->assertOk();

    Mail::assertQueued(OrderPaid::class, 1);
});

/**
 * **The property phase A was built for, and this is the first caller ever to
 * exercise it.** Stripe redelivers events as a matter of routine and managed
 * queues deliver at least once, so this is Tuesday rather than an edge case.
 */
it('changes nothing the second time the same payment is reported', function () {
    $customer = Customer::factory()->create(['password' => bcrypt('already-claimed')]);
    $order = orderCosting(7500, customer: $customer, pass: true);

    $payload = stripeEvent('payment_intent.succeeded', paymentIntent([
        'metadata' => ['order_id' => (string) $order->id],
    ]));

    postToStripeWebhook($payload)->assertOk();

    $grantedAt = $customer->fresh()->viewing_room_access_at;
    $paidAt = $order->fresh()->paid_at;

    $this->travel(1)->hour();

    // Byte for byte the same event, signed afresh the way a redelivery is.
    postToStripeWebhook($payload)->assertOk();

    $order->refresh();

    expect($order->paid_at->equalTo($paidAt))->toBeTrue()
        ->and($customer->fresh()->viewing_room_access_at->equalTo($grantedAt))->toBeTrue();

    // One payment, one receipt, however many times it is reported.
    Mail::assertQueued(OrderPaid::class, 1);
});

/**
 * **Answer fast, do the work on the queue.**
 *
 * Stripe retries anything that is not a 2xx, indefinitely, so an exception
 * during fulfillment would become an endless redelivery of a payment that
 * already succeeded. The 200 must not be waiting on any of it.
 */
it('answers before the order has been settled, with the work queued', function () {
    Queue::fake();

    $order = orderCosting(7500);

    postToStripeWebhook(stripeEvent('payment_intent.succeeded', paymentIntent([
        'id' => 'pi_queued',
        'metadata' => ['order_id' => (string) $order->id],
    ])))->assertOk();

    // Nothing has happened yet, and the answer went out anyway.
    expect($order->fresh()->status)->toBe(OrderStatus::Pending);

    Queue::assertPushed(SettleStripePayment::class, function (SettleStripePayment $job) use ($order): bool {
        return $job->payment->id === 'pi_queued'
            && $job->payment->orderId === $order->id
            && $job->payment->amount === 7500;
    });
});

it('queues nothing at all for a refused notification', function () {
    Queue::fake();

    $payload = stripeEvent('payment_intent.succeeded', paymentIntent());

    postToStripeWebhook($payload, signature: 't='.time().',v1='.str_repeat('a', 64))
        ->assertStatus(400);

    Queue::assertNothingPushed();
});

/*
| Refusals. A forgery must change no state at all.
*/

it('refuses a forged signature and moves no order', function () {
    $order = orderCosting(7500);

    $payload = stripeEvent('payment_intent.succeeded', paymentIntent([
        'metadata' => ['order_id' => (string) $order->id],
    ]));

    postToStripeWebhook($payload, signature: 't='.time().',v1='.str_repeat('a', 64))
        ->assertStatus(400);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
    Mail::assertNothingQueued();
});

it('refuses a signature made with somebody else"s secret', function () {
    $order = orderCosting(7500);

    $payload = stripeEvent('payment_intent.succeeded', paymentIntent([
        'metadata' => ['order_id' => (string) $order->id],
    ]));

    // The wrong endpoint's secret fails identically to a forgery, which is the
    // failure mode worth knowing about: staging's secret in production looks
    // exactly like an attack.
    postToStripeWebhook($payload, signature: signedWith($payload, secret: 'whsec_someotherendpoint'))
        ->assertStatus(400);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

it('refuses a body altered after it was signed', function () {
    $order = orderCosting(7500);

    $honest = stripeEvent('payment_intent.succeeded', paymentIntent([
        'amount' => 100,
        'amount_received' => 100,
        'metadata' => ['order_id' => (string) $order->id],
    ]));

    $signature = signedWith($honest);

    // One number changed, in a way that would settle the order if the bytes
    // were not what is verified.
    $tampered = str_replace('"amount":100', '"amount":7500', $honest);

    postToStripeWebhook($tampered, $signature)->assertStatus(400);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

it('refuses a request carrying no signature at all', function () {
    postToStripeWebhook(stripeEvent('payment_intent.succeeded', paymentIntent()), signature: '')
        ->assertStatus(400);
});

/**
 * **Refuses everything, loudly.** Failing open on a webhook is failing open on
 * granting paid access for free.
 */
it('refuses every notification when no signing secret is configured', function () {
    config()->set('services.stripe.webhook_secret', null);

    $order = orderCosting(7500);

    $payload = stripeEvent('payment_intent.succeeded', paymentIntent([
        'metadata' => ['order_id' => (string) $order->id],
    ]));

    // Signed correctly by every measure except that this environment cannot
    // check it. That has to be a refusal rather than a shrug.
    postToStripeWebhook($payload, signature: signedWith($payload))->assertStatus(400);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

/*
| Replay protection, and the trap that comes with pinning a fixture.
*/

/**
 * A genuinely old signature, correct in every other way, refused **by the
 * endpoint**. This is the behavioural half of the tolerance story.
 */
it('refuses a signature old enough to be a replay', function () {
    $order = orderCosting(7500);

    $payload = stripeEvent('payment_intent.succeeded', paymentIntent([
        'metadata' => ['order_id' => (string) $order->id],
    ]));

    $anHourAgo = time() - 3600;

    postToStripeWebhook($payload, signature: signedWith($payload, timestamp: $anHourAgo))
        ->assertStatus(400);

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
});

/**
 * **The endpoint carries no tolerance override at all**, which is the half a
 * behavioural test cannot see.
 *
 * `stripe-php` verifies with a 300 second tolerance, so a signature captured
 * from Stripe and committed here is permanently expired and the test pinning it
 * has to widen the window. **Widen it in the wrong place and replay protection
 * is off in production with nothing going red.** So the widening lives in one
 * argument that one test passes, and this proves the endpoint does not pass it.
 */
it('never widens the tolerance the live endpoint verifies with', function () {
    $spy = new class extends StripeSignature
    {
        public static bool $called = false;

        public static ?int $tolerance = null;

        public function verify(string $payload, string $header, ?int $tolerance = null): Event
        {
            self::$called = true;
            self::$tolerance = $tolerance;

            return Event::constructFrom(['id' => 'evt_spy', 'type' => 'ping', 'data' => ['object' => []]]);
        }
    };

    app()->instance(StripeSignature::class, $spy);

    postToStripeWebhook(stripeEvent('payment_intent.succeeded', paymentIntent()))->assertOk();

    expect($spy::$called)->toBeTrue()
        // Null is the endpoint passing nothing, which is the verifier using
        // stripe-php's default. Any number here, from config or otherwise,
        // would be replay protection with a knob on it.
        ->and($spy::$tolerance)->toBeNull();
});

it('leaves stripe-php to say what the default tolerance is', function () {
    // Not our number to choose. Pinned so that a change to it is a decision
    // somebody made rather than a version bump nobody read.
    expect(Webhook::DEFAULT_TOLERANCE)->toBe(300);

    // And no way to set it from config, which is the shape a mistake would take.
    expect(config('services.stripe'))->not->toHaveKey('webhook_tolerance');
});

/*
| Events this site cannot use. All of them answer 200, because Stripe retries a
| non-2xx indefinitely.
*/

it('answers an event about an order this environment does not have', function () {
    Log::spy();

    postToStripeWebhook(stripeEvent('payment_intent.succeeded', paymentIntent([
        'metadata' => ['order_id' => '999999'],
    ])))->assertOk();

    Log::shouldHaveReceived('info')->withArgs(
        fn (string $message): bool => str_contains($message, 'does not have'),
    );
});

it('answers an event carrying no order id at all', function () {
    postToStripeWebhook(stripeEvent('payment_intent.succeeded', paymentIntent([
        'metadata' => [],
    ])))->assertOk();
});

it('does not mark an order paid for an amount that disagrees with it', function () {
    Log::spy();

    $order = orderCosting(7500);

    postToStripeWebhook(stripeEvent('payment_intent.succeeded', paymentIntent([
        'amount' => 100,
        'amount_received' => 100,
        'metadata' => ['order_id' => (string) $order->id],
    ])))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->fresh()->charged_amount)->toBeNull();

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message): bool => str_contains($message, 'did not match'),
    );

    Mail::assertNothingQueued();
});

it('does not mark an order paid in a currency it was not placed in', function () {
    $order = orderCosting(7500, 'GBP');

    postToStripeWebhook(stripeEvent('payment_intent.succeeded', paymentIntent([
        'currency' => 'usd',
        'metadata' => ['order_id' => (string) $order->id],
    ])))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($order->fresh()->charged_amount)->toBeNull();
});

it('leaves an order pending when the payment failed', function () {
    $order = orderCosting(7500);

    postToStripeWebhook(stripeEvent('payment_intent.payment_failed', paymentIntent([
        'status' => 'requires_payment_method',
        'amount_received' => 0,
        'metadata' => ['order_id' => (string) $order->id],
    ])))->assertOk();

    expect($order->fresh()->status)->toBe(OrderStatus::Pending);
    Mail::assertNothingQueued();
});

it('answers an event type nobody subscribed to', function () {
    postToStripeWebhook(stripeEvent('customer.subscription.created', [
        'id' => 'sub_1',
        'object' => 'subscription',
    ]))->assertOk()->assertExactJson(['received' => true]);
});

/*
| Chargebacks, which arrive whether or not refunds are offered.
*/

it('tells Jennifer when a customer disputes a payment', function () {
    $order = orderCosting(7500);
    $order->forceFill(['stripe_payment_intent_id' => 'pi_disputed'])->save();

    postToStripeWebhook(stripeEvent('charge.dispute.created', [
        'id' => 'dp_1',
        'object' => 'dispute',
        'amount' => 7500,
        'currency' => 'usd',
        'reason' => 'fraudulent',
        'payment_intent' => 'pi_disputed',
        'status' => 'warning_needs_response',
    ]))->assertOk();

    Mail::assertQueued(PaymentDisputed::class, function (PaymentDisputed $mail) use ($order): bool {
        return $mail->disputeId === 'dp_1'
            && $mail->amount === 7500
            && $mail->order?->id === $order->id
            && $mail->hasTo(config('mail.notify.fulfillment'));
    });
});

it('changes nothing about a disputed order, because nothing is revoked', function () {
    $customer = Customer::factory()->create(['password' => bcrypt('already-claimed')]);
    $order = orderCosting(7500, customer: $customer, pass: true);

    $order->forceFill([
        'status' => OrderStatus::Paid,
        'paid_at' => now(),
        'stripe_payment_intent_id' => 'pi_disputed',
    ])->save();

    $customer->grantViewingRoomAccess();

    postToStripeWebhook(stripeEvent('charge.dispute.created', [
        'id' => 'dp_2',
        'object' => 'dispute',
        'amount' => 7500,
        'currency' => 'usd',
        'reason' => 'fraudulent',
        'payment_intent' => 'pi_disputed',
        'status' => 'warning_needs_response',
    ]))->assertOk();

    // There are no refunds and nothing is revoked. Decided 25 August 2026, and
    // recorded rather than hidden.
    expect($order->fresh()->status)->toBe(OrderStatus::Paid)
        ->and($customer->fresh()->hasViewingRoomAccess())->toBeTrue();
});

it('still tells her about a dispute it cannot match to an order', function () {
    postToStripeWebhook(stripeEvent('charge.dispute.created', [
        'id' => 'dp_3',
        'object' => 'dispute',
        'amount' => 4500,
        'currency' => 'gbp',
        'reason' => 'general',
        'payment_intent' => 'pi_nobody_here',
        'status' => 'warning_needs_response',
    ]))->assertOk();

    Mail::assertQueued(PaymentDisputed::class, fn (PaymentDisputed $mail): bool => $mail->order === null);
});

/*
| Shape and plumbing.
*/

it('carries no locale segment, since a webhook is not content', function () {
    $payload = stripeEvent('payment_intent.succeeded', paymentIntent());

    test()->call('POST', '/api/v1/en/webhooks/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => signedWith($payload),
    ], $payload)->assertNotFound();
});

it('counts its own requests rather than sharing a bucket', function () {
    $middleware = collect(Route::getRoutes()->getByName('webhooks.stripe')->gatherMiddleware())
        ->first(fn (string $m): bool => str_starts_with($m, 'throttle:'));

    expect($middleware)->toBeString();

    [, $arguments] = explode(':', $middleware, 2);

    expect(explode(',', $arguments))->toHaveCount(3)
        ->and(explode(',', $arguments)[2])->toBe('stripe-webhook');
});
