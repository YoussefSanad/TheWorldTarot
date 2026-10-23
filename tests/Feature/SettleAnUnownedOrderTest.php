<?php

use App\Actions\MarkOrderPaid;
use App\Actions\RecordStripePayment;
use App\Enums\OrderStatus;
use App\Enums\Recording;
use App\Mail\OrderPaid;
use App\Mail\SetPasswordLink;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Payments\OrderBelongsToNobody;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Collection;
use Stripe\Service\Checkout\SessionService;
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

    expect(app(RecordStripePayment::class)(paymentFor($order)))->toBe(Recording::Settled);

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

    expect(app(RecordStripePayment::class)(paymentFor($order)))->toBe(Recording::Settled)
        ->and($order->refresh()->customer_id)->toBe($customer->id);
});

it('leaves the name empty for one Stripe did not collect', function () {
    $order = unownedOrderWorth(2500);
    stripeAnswering([['email' => 'jennifer@example.com', 'name' => null]]);

    app(RecordStripePayment::class)(paymentFor($order));

    // ~~A receipt reading "Hi ," is worse than one reading "Hi jennifer".~~
    // **Reversed 30 August 2026, issue #52.** That was true while a nameless
    // greeting was broken, and it stopped being true the moment `Greeting`
    // made one read properly. `jennifer` is the routing half of an address,
    // not a name, and addressing somebody by it is a guess this application
    // was making on the strength of never having asked.
    expect($order->refresh()->customer->name)->toBeNull();
});

it('leaves the order pending when Stripe names nobody', function () {
    $order = unownedOrderWorth(2500);

    // No Session, and a charge that names nobody either. Both sources
    // exhausted, which is the only shape that leaves an order pending.
    stripeAnswering([], charge: stripeCharge(null));

    expect(app(RecordStripePayment::class)(paymentFor($order)))->toBe(Recording::BuyerUnknown);

    $order->refresh();

    // **The money is not lost and neither is the order.** It holds the intent
    // that paid for it, so orders:reconcile comes back to it and a later
    // attempt settles it properly. Somebody is served late rather than never.
    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->customer_id)->toBeNull()
        // **Changed 23 October 2026, step 5.** This used to assert 2500, which
        // an unconditional save wrote before the transition. Only settlement
        // writes the amount now, and this order has not settled.
        ->and($order->charged_amount)->toBeNull()
        ->and($order->stripe_payment_intent_id)->toBe('pi_hosted');

    Mail::assertNothingSent();
    Mail::assertNothingQueued();
});

it('leaves the order pending when the session collected no address', function () {
    $order = unownedOrderWorth(2500);
    stripeAnswering([['email' => null, 'name' => 'Jennifer']], charge: stripeCharge(null));

    expect(app(RecordStripePayment::class)(paymentFor($order)))->toBe(Recording::BuyerUnknown)
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);

    // A name with no address cannot make an account, because the address is
    // what identifies one.
    expect(Customer::count())->toBe(0);
});

it('settles on a later attempt once Stripe can say who paid', function () {
    $order = unownedOrderWorth(2500);
    stripeAnswering([], charge: stripeCharge(null));

    expect(app(RecordStripePayment::class)(paymentFor($order)))->toBe(Recording::BuyerUnknown);

    stripeAnswering([['email' => 'jennifer@example.com', 'name' => 'Jennifer']]);

    expect(app(RecordStripePayment::class)(paymentFor($order)))->toBe(Recording::Settled)
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
});

it('settles an unowned order exactly once however many times it is redelivered', function () {
    // A pass, so that the claim link is in play at all and the redelivery has
    // two messages to be counted against rather than one.
    $order = unownedOrderWorth(2500, product: Product::factory()->pass());
    stripeAnswering([['email' => 'jennifer@example.com', 'name' => 'Jennifer']]);

    expect(app(RecordStripePayment::class)(paymentFor($order)))->toBe(Recording::Settled)
        // A redelivery finds the work done. It does not make a second account
        // and does not send a second receipt.
        ->and(app(RecordStripePayment::class)(paymentFor($order)))->toBe(Recording::AlreadySettled)
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

    expect(fn () => app(MarkOrderPaid::class)($order, paidBy($order)))
        ->toThrow(OrderBelongsToNobody::class);

    expect($order->refresh()->status)->toBe(OrderStatus::Pending);
});

/**
 * **The two answers that used to be the same `false`.**
 *
 * One is a fault: somebody has been charged, Stripe named nobody, and the order
 * is sitting pending waiting for a person to be found. The other is a success:
 * the work was already done and a redelivery found it done. Reported to an
 * operator as one word, they are indistinguishable, which is how
 * `orders:reconcile` came to print a refused settlement as a healthy one.
 *
 * **Asserted on the return value alone**, with no `Log` spy anywhere near it.
 * The log line is still written by the action and is still the record. It is no
 * longer the only place the difference exists.
 */
it('tells a buyer nobody could name apart from an order already settled', function () {
    $unknown = unownedOrderWorth(2500);
    stripeAnswering([], charge: stripeCharge(null));

    $refused = app(RecordStripePayment::class)(paymentFor($unknown));

    $settled = unownedOrderWorth(2500, 'pi_second');
    stripeAnswering([['email' => 'jennifer@example.com', 'name' => 'Jennifer']]);

    app(RecordStripePayment::class)(paymentFor($settled, 'pi_second'));
    $redelivered = app(RecordStripePayment::class)(paymentFor($settled, 'pi_second'));

    expect($refused)->toBe(Recording::BuyerUnknown)
        ->and($redelivered)->toBe(Recording::AlreadySettled)
        ->and($refused)->not->toBe($redelivered)
        // And the difference has a name rather than being two shades of the
        // same word: one is a fault somebody has to see, the other is not.
        ->and($refused->fault())->toBeTrue()
        ->and($redelivered->fault())->toBeFalse();
});

/*
| Step 5 of the design review fixes: which intent is the paying one.
|
| An order's `stripe_payment_intent_id` is not the payment that arrived. On the
| hosted road it is null (a Checkout Session has no PaymentIntent when it is
| created, R2), and on a retried wallet payment it names an earlier attempt.
| Settlement used to hide both by writing the paying intent onto the column
| before it looked, which is also the write that let a second payment overwrite
| the first. With it gone, everything has to read `$payment->id`.
*/

it('asks the resolvers about the paying intent when the order column is null', function () {
    $asked = [];
    $order = unownedOrderWorth(2500, 'pi_placeholder');
    $order->forceFill(['stripe_payment_intent_id' => null])->save();

    stripeAnswering([['email' => 'jennifer@example.com', 'name' => 'Jennifer']], $asked);

    expect(app(RecordStripePayment::class)(paymentFor($order, 'pi_paying')))->toBe(Recording::Settled);

    expect($asked)->toHaveCount(1)
        ->and($asked[0]['payment_intent'])->toBe('pi_paying')
        ->and($order->refresh()->stripe_payment_intent_id)->toBe('pi_paying')
        ->and($order->charged_amount)->toBe(2500);
});

it('asks the resolvers about the paying intent and not an earlier one the order holds', function () {
    $asked = [];
    $order = unownedOrderWorth(2500, 'pi_wallet_abandoned');

    stripeAnswering([['email' => 'jennifer@example.com', 'name' => 'Jennifer']], $asked);

    // A customer who retried: a different intent paid than the one the order
    // last recorded. The one that paid is the one whose buyer is wanted.
    expect(app(RecordStripePayment::class)(paymentFor($order, 'pi_paying')))->toBe(Recording::Settled);

    expect($asked[0]['payment_intent'])->toBe('pi_paying')
        ->and($order->refresh()->stripe_payment_intent_id)->toBe('pi_paying');
});

it('leaves the paying intent on a pending hosted order whose buyer nobody could name', function () {
    $order = unownedOrderWorth(2500, 'pi_placeholder');
    $order->forceFill(['stripe_payment_intent_id' => null])->save();

    stripeAnswering([], charge: stripeCharge(null));

    expect(app(RecordStripePayment::class)(paymentFor($order, 'pi_paying')))->toBe(Recording::BuyerUnknown);

    $order->refresh();

    // The narrow write the sweep depends on: without the intent on the column
    // `orders:reconcile` has nothing to ask Stripe about, and the customer who
    // paid is never served.
    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->stripe_payment_intent_id)->toBe('pi_paying')
        ->and($order->charged_amount)->toBeNull();
});

it('reports a second distinct intent for a paid order as PaidTwice and logs how to find it', function () {
    Log::spy();

    $order = orderWorth(2500);

    expect(app(RecordStripePayment::class)(paymentFor($order, 'pi_first')))->toBe(Recording::Settled);

    $paidAt = $order->fresh()->paid_at;

    // Forget the first settlement's mail, so what is counted is the second's.
    Mail::fake();

    $outcome = app(RecordStripePayment::class)(paymentFor($order, 'pi_second'));

    expect($outcome)->toBe(Recording::PaidTwice)
        ->and($outcome->fault())->toBeTrue();

    $order->refresh();

    expect($order->stripe_payment_intent_id)->toBe('pi_first')
        ->and($order->charged_amount)->toBe(2500)
        ->and($order->paid_at->eq($paidAt))->toBeTrue();

    Mail::assertNothingQueued();

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'Refund it by hand in Stripe')
            && $context['order_id'] === $order->id
            && $context['settling_intent'] === 'pi_first'
            && $context['duplicate_intent'] === 'pi_second',
    );
});

it('reports the settling intent redelivered as AlreadySettled, with nothing logged as an error', function () {
    Log::spy();

    $order = orderWorth(2500);

    app(RecordStripePayment::class)(paymentFor($order, 'pi_first'));

    expect(app(RecordStripePayment::class)(paymentFor($order, 'pi_first')))->toBe(Recording::AlreadySettled);

    Log::shouldNotHaveReceived('error');
});

/*
| Step 5, security review: identity is written from the model read before the
| row lock. The race is simulated from inside the Stripe call that names the
| buyer, which is the window between that read and the transition: intent A
| settles the order for alice while intent B is still asking who paid.
*/

it('keeps the customer who settled the order when a second intent names somebody else mid-call', function () {
    $order = unownedOrderWorth(2500, 'pi_hosted', Product::factory()->pass());
    $alice = Customer::factory()->create(['email' => 'alice@example.com']);

    $sessions = Mockery::mock(SessionService::class);
    $sessions->shouldReceive('all')->andReturnUsing(function () use ($order, $alice): Collection {
        Order::whereKey($order->id)->update([
            'status' => OrderStatus::Paid->value,
            'paid_at' => now(),
            'charged_amount' => $order->total_amount,
            'stripe_payment_intent_id' => 'pi_A',
            'customer_id' => $alice->id,
        ]);

        return Collection::constructFrom(['data' => [[
            'customer_details' => ['email' => 'bob@example.com', 'name' => 'Bob'],
        ]]]);
    });
    substitutedStripe(Mockery::mock(PaymentIntentService::class), $sessions);

    expect(app(RecordStripePayment::class)(paymentFor($order, 'pi_B')))->toBe(Recording::PaidTwice);

    $order->refresh();

    expect($order->customer_id)->toBe($alice->id)
        ->and($order->stripe_payment_intent_id)->toBe('pi_A')
        ->and(Customer::firstWhere('email', 'bob@example.com')?->hasViewingRoomAccess())->not->toBeTrue();
});

it('still fills the customer on a pending order that has none', function () {
    $order = unownedOrderWorth(2500, 'pi_hosted', Product::factory()->pass());
    stripeAnswering([['email' => 'bob@example.com', 'name' => 'Bob']]);

    expect(app(RecordStripePayment::class)(paymentFor($order)))->toBe(Recording::Settled);

    $bob = Customer::firstWhere('email', 'bob@example.com');

    expect($order->refresh()->customer_id)->toBe($bob->id)
        ->and($bob->hasViewingRoomAccess())->toBeTrue();
});

it('settles with the later intent while the order is still pending, and keeps that one', function () {
    // The rule is about an order that is no longer pending. A pending order
    // that held an earlier attempt's intent is settled by whichever intent
    // actually succeeded, and from then on that is the intent it names.
    $order = orderWorth(2500);
    $order->forceFill(['stripe_payment_intent_id' => 'pi_earlier_attempt'])->save();

    expect(app(RecordStripePayment::class)(paymentFor($order, 'pi_that_paid')))->toBe(Recording::Settled)
        ->and(app(RecordStripePayment::class)(paymentFor($order, 'pi_earlier_attempt')))->toBe(Recording::PaidTwice);

    expect($order->refresh()->stripe_payment_intent_id)->toBe('pi_that_paid');
});
