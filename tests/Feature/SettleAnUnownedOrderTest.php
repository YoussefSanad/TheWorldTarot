<?php

use App\Actions\MarkOrderPaid;
use App\Actions\RecordStripePayment;
use App\Enums\OrderStatus;
use App\Mail\OrderPaid;
use App\Mail\SetPasswordLink;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Payments\OrderBelongsToNobody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Service\PaymentIntentService;

uses(RefreshDatabase::class);

/**
 * Who an order belongs to, when nobody said at the time.
 *
 * **Stripe's hosted page collects the buyer's email after the order exists**,
 * so an order can be pending and unowned, and settlement is where it acquires
 * an identity. See `docs/adr/0002-the-redirect-case-comes-back.md`.
 *
 * `payment_intent.succeeded` is still the only event that settles anything and
 * a PaymentIntent carries no `customer_details`, so the Session is fetched by
 * its intent. That fetch is what these tests substitute. **A mock agrees with
 * this code whatever this code does**: the authority on Stripe remains the
 * captured signature vector and the walk in tests/Staging.
 */
beforeEach(function () {
    Mail::fake();
});

/**
 * Stripe answering with the Checkout Sessions this test says, and with a
 * PaymentIntent service that has no expectations at all.
 *
 * **So reaching for the charge fails the test rather than quietly finding
 * nobody.** Identity has had a second source since 29 August 2026, the intent's
 * own charge, for the wallet road that has no Session. This file is the hosted
 * road, where the Session is what answers, and the tests here that expect
 * nobody hand `$charge` a service explicitly so that the fall-through is
 * something they say rather than something they get.
 *
 * @param  array<int, array{email: ?string, name: ?string}>  $sessions
 */
function stripeAnswering(array $sessions, ?array &$asked = null, ?PaymentIntentService $charge = null): void
{
    substitutedStripe($charge ?? Mockery::mock(PaymentIntentService::class), stripeSessions($sessions, $asked));
}

it('attaches the buyer Stripe collected and settles the order', function () {
    $order = unownedOrderWorth(2500);
    stripeAnswering([['email' => 'Jennifer@Example.com', 'name' => 'Jennifer Eisenberger']]);

    expect(app(RecordStripePayment::class)(paymentFor($order)))->toBeTrue();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        // Lowercased, so this is the same match Customer::firstOrCreate makes
        // when an address is typed into the form instead.
        ->and($order->customer->email)->toBe('jennifer@example.com')
        ->and($order->customer->name)->toBe('Jennifer Eisenberger')
        // Unclaimed, exactly as an account made at placement is.
        ->and($order->customer->password)->toBeNull();
});

it('asks Stripe for the session belonging to the intent that paid', function () {
    $asked = [];
    $order = unownedOrderWorth(2500, 'pi_specific');
    stripeAnswering([['email' => 'jennifer@example.com', 'name' => 'Jennifer']], $asked);

    app(RecordStripePayment::class)(paymentFor($order, 'pi_specific'));

    expect($asked)->toHaveCount(1)
        ->and($asked[0]['payment_intent'])->toBe('pi_specific')
        ->and($asked[0]['limit'])->toBe(1);
});

it('everything downstream of settlement works for an identity that arrived late', function () {
    // A pass, because the claim link follows the Viewing Room rather than the
    // payment. On a reading this settles exactly as well and sends one message
    // fewer, which is issue #51 and not what this test is watching.
    $order = unownedOrderWorth(2500, product: Product::factory()->pass());
    stripeAnswering([['email' => 'jennifer@example.com', 'name' => 'Jennifer']]);

    app(RecordStripePayment::class)(paymentFor($order));

    // The receipt, and the invitation to choose a password for the pass that
    // was just granted.
    Mail::assertQueued(OrderPaid::class);
    Mail::assertQueued(SetPasswordLink::class);
});

it('grants the pass to the customer who turned out to have bought it', function () {
    $order = Order::factory()
        ->forProduct(Product::factory()->pass()->create(['price_usd' => 4500]))
        ->create(['customer_id' => null, 'stripe_payment_intent_id' => 'pi_hosted']);

    stripeAnswering([['email' => 'jennifer@example.com', 'name' => 'Jennifer']]);

    app(RecordStripePayment::class)(paymentFor($order));

    expect(Customer::firstWhere('email', 'jennifer@example.com')->hasViewingRoomAccess())->toBeTrue();
});

it('uses the account an address already has rather than making a second', function () {
    $existing = Customer::factory()->create(['email' => 'jennifer@example.com', 'name' => 'Jennifer']);
    $order = unownedOrderWorth(2500);
    stripeAnswering([['email' => 'jennifer@example.com', 'name' => 'Somebody Else']]);

    app(RecordStripePayment::class)(paymentFor($order));

    expect(Customer::count())->toBe(1)
        ->and($order->refresh()->customer_id)->toBe($existing->id)
        // The name on the account is not rewritten by whatever was typed into
        // a checkout. It is their account and their name.
        ->and($existing->fresh()->name)->toBe('Jennifer');
});

it('never overwrites an identity the order already carried', function () {
    // A signed-in customer owns their order from the moment they placed it,
    // and an address typed into the form is what the account was made from.
    // Stripe's copy fills a gap, it does not win an argument.
    $customer = Customer::factory()->create(['email' => 'owner@example.com']);
    $order = Order::factory()
        ->for($customer)
        ->forProduct(Product::factory()->create(['price_usd' => 2500]))
        ->create(['stripe_payment_intent_id' => 'pi_hosted']);

    // No sessions service at all, so reaching for one would fail the test.
    substitutedStripe(Mockery::mock(PaymentIntentService::class));

    expect(app(RecordStripePayment::class)(paymentFor($order)))->toBeTrue()
        ->and($order->refresh()->customer_id)->toBe($customer->id);
});

it('falls back to the address for a name Stripe did not collect', function () {
    $order = unownedOrderWorth(2500);
    stripeAnswering([['email' => 'jennifer@example.com', 'name' => null]]);

    app(RecordStripePayment::class)(paymentFor($order));

    // A receipt reading "Hi ," is worse than one reading "Hi jennifer".
    expect($order->refresh()->customer->name)->toBe('jennifer');
});

it('leaves the order pending and shouts when Stripe names nobody', function () {
    Log::spy();

    $order = unownedOrderWorth(2500);

    // No Session, and a charge that names nobody either. Both sources
    // exhausted, which is the only shape that leaves an order pending.
    stripeAnswering([], charge: stripeCharge(null));

    expect(app(RecordStripePayment::class)(paymentFor($order)))->toBeFalse();

    $order->refresh();

    // **The money is not lost and neither is the order.** It holds the intent
    // that paid for it, so orders:reconcile comes back to it and a later
    // attempt settles it properly. Somebody is served late rather than never.
    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->customer_id)->toBeNull()
        ->and($order->charged_amount)->toBe(2500)
        ->and($order->stripe_payment_intent_id)->toBe('pi_hosted');

    Mail::assertNothingSent();
    Mail::assertNothingQueued();

    // Once, for the order left pending. The charge answered, it simply named
    // nobody, so nothing shouted about reaching Stripe.
    Log::shouldHaveReceived('error')->once();
});

it('leaves the order pending when the session collected no address', function () {
    Log::spy();

    $order = unownedOrderWorth(2500);
    stripeAnswering([['email' => null, 'name' => 'Jennifer']], charge: stripeCharge(null));

    expect(app(RecordStripePayment::class)(paymentFor($order)))->toBeFalse()
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);

    // A name with no address cannot make an account, because the address is
    // what identifies one.
    expect(Customer::count())->toBe(0);
});

it('settles on a later attempt once Stripe can say who paid', function () {
    $order = unownedOrderWorth(2500);
    stripeAnswering([], charge: stripeCharge(null));

    expect(app(RecordStripePayment::class)(paymentFor($order)))->toBeFalse();

    stripeAnswering([['email' => 'jennifer@example.com', 'name' => 'Jennifer']]);

    expect(app(RecordStripePayment::class)(paymentFor($order)))->toBeTrue()
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('settles an unowned order exactly once however many times it is redelivered', function () {
    // A pass, so that the claim link is in play at all and the redelivery has
    // two messages to be counted against rather than one.
    $order = unownedOrderWorth(2500, product: Product::factory()->pass());
    stripeAnswering([['email' => 'jennifer@example.com', 'name' => 'Jennifer']]);

    expect(app(RecordStripePayment::class)(paymentFor($order)))->toBeTrue()
        // A redelivery finds the work done. It does not make a second account
        // and does not send a second receipt.
        ->and(app(RecordStripePayment::class)(paymentFor($order)))->toBeFalse()
        ->and(Customer::count())->toBe(1);

    // One receipt and one claim link. Not two of either. Nothing goes to
    // Jennifer here, because a pass has no reading to write.
    Mail::assertQueued(OrderPaid::class, 1);
    Mail::assertQueued(SetPasswordLink::class, 1);
});

it('refuses to settle an order that reached the transition holding nobody', function () {
    // A fault in the caller rather than a state to handle. Everything the
    // transition does is addressed to somebody.
    $order = unownedOrderWorth(2500);

    expect(fn () => app(MarkOrderPaid::class)($order))
        ->toThrow(OrderBelongsToNobody::class);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});
