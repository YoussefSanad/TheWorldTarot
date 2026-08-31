<?php

use App\Actions\RecordStripePayment;
use App\Enums\OrderStatus;
use App\Mail\OrderPaid;
use App\Mail\SetPasswordLink;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Stripe\Exception\ApiConnectionException;
use Stripe\Service\PaymentIntentService;

uses(RefreshDatabase::class);

/**
 * Who a wallet payment belongs to, when the order was placed by nobody.
 *
 * **The trap this whole change exists to avoid.** `identify()` asked Stripe for
 * the Checkout Session belonging to the intent, and a wallet PaymentIntent has
 * no Session. The list would come back empty, the resolver would answer null,
 * the order would be left pending, and the customer would already have been
 * charged. `ReconcileOrders` would come back to it and hit the same empty list
 * every time, forever. See docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md.
 *
 * So identity has a second source, the intent's own charge, and `identify()`
 * tries the Session first and the charge after it. Session first leaves the
 * hosted road bit for bit as it was, and costs the wallet road one empty list
 * on a job that already holds six hours of retries.
 *
 * **A mock agrees with this code whatever this code does.** Nothing here says
 * a real wallet populates `latest_charge.billing_details.email`, and the whole
 * identity fix rests on that one fact. Only a real wallet on a real device
 * settles it, which is issue #44. See .claude/external-services.md.
 */
beforeEach(function () {
    Mail::fake();
});

/**
 * Stripe with no Checkout Session for this intent, which is what a wallet
 * payment actually looks like, and a charge that answers however a test says.
 *
 * @param  array{email: ?string, name: ?string}|null  $billing
 */
function stripeWithNoSessionAndACharge(?array $billing, ?array &$askedForCharge = null): void
{
    substitutedStripe(stripeCharge($billing, $askedForCharge), stripeSessions([]));
}

it('attaches the buyer the wallet collected on the charge, and settles the order', function () {
    $order = unownedOrderWorth(2500, 'pi_wallet');
    stripeWithNoSessionAndACharge(['email' => 'Jennifer@Example.com', 'name' => 'Jennifer Eisenberger']);

    expect(app(RecordStripePayment::class)(paymentFor($order, 'pi_wallet')))->toBeTrue();

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid)
        // Lowercased, so this is the same match Customer::firstOrCreate makes
        // when an address is typed into the form instead.
        ->and($order->customer->email)->toBe('jennifer@example.com')
        ->and($order->customer->name)->toBe('Jennifer Eisenberger')
        // Unclaimed, exactly as an account made at placement is.
        ->and($order->customer->password)->toBeNull();
});

/**
 * **The absence is the acceptance criterion**, which is why this is its own
 * test rather than another expectation on the one above.
 *
 * The issue asked that a wallet payment settle end to end *without* the "left
 * pending" line, because that line firing on every wallet payment ever taken is
 * the entire defect this road was built around. A test that only checks the
 * order reached paid would pass just as well against a version that settled it
 * and shouted anyway, and the shout is what a person on call would be woken by.
 */
it('settles a wallet payment without logging an error at all', function () {
    Log::spy();

    $order = unownedOrderWorth(2500, 'pi_wallet');
    stripeWithNoSessionAndACharge(['email' => 'jennifer@example.com', 'name' => 'Jennifer']);

    expect(app(RecordStripePayment::class)(paymentFor($order, 'pi_wallet')))->toBeTrue()
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);

    // Not merely "not the pending line". An empty Session list is the normal
    // shape of a wallet payment rather than a fault, so nothing on the happy
    // road has anything to report.
    Log::shouldNotHaveReceived('error');
});

/**
 * **The expansion is the whole call.** An unexpanded `latest_charge` is a
 * string id, which carries no billing details at all, so a retrieve without it
 * would answer nobody for a payment that named somebody.
 */
it('asks Stripe to expand the charge behind the intent that paid', function () {
    $asked = [];
    $order = unownedOrderWorth(2500, 'pi_specific');
    stripeWithNoSessionAndACharge(['email' => 'jennifer@example.com', 'name' => 'Jennifer'], $asked);

    app(RecordStripePayment::class)(paymentFor($order, 'pi_specific'));

    expect($asked)->toHaveCount(1)
        ->and($asked[0]['id'])->toBe('pi_specific')
        ->and($asked[0]['params']['expand'])->toBe(['latest_charge']);
});

/**
 * **Session first, and the order is not an accident.** A Session that names
 * somebody is answered without the charge ever being retrieved, so the road
 * that already works pays nothing for the road that was added beside it.
 *
 * **A Session that answers nobody does fall through**, which is the case below
 * this one. The fall through is `??`, so it fires on anything the first
 * resolver cannot answer rather than only on a missing Session, and that is
 * wanted: every one of those cases ends with a charged customer nobody can
 * settle. See the docblock on `RecordStripePayment::identify()`.
 */
it('tries the checkout session first and never reaches the charge when it answers', function () {
    $order = unownedOrderWorth(2500, 'pi_hosted_shape');

    // A Session that names somebody, and a payment intents service with no
    // expectations at all, so retrieving the charge would fail this test.
    substitutedStripe(
        Mockery::mock(PaymentIntentService::class),
        stripeSessions([['email' => 'session@example.com', 'name' => 'From The Session']]),
    );

    expect(app(RecordStripePayment::class)(paymentFor($order, 'pi_hosted_shape')))->toBeTrue()
        ->and($order->refresh()->customer->email)->toBe('session@example.com');
});

it('leaves the name empty for one the wallet did not collect', function () {
    $order = unownedOrderWorth(2500, 'pi_wallet');
    stripeWithNoSessionAndACharge(['email' => 'jennifer@example.com', 'name' => null]);

    app(RecordStripePayment::class)(paymentFor($order, 'pi_wallet'));

    // ~~A receipt reading "Hi ," is worse than one reading "Hi jennifer".~~
    // **Reversed 30 August 2026, issue #52.** That was true while a nameless
    // greeting was broken, and it stopped being true the moment `Greeting`
    // made one read properly. `jennifer` is the routing half of an address,
    // not a name, and addressing somebody by it is a guess this application
    // was making on the strength of never having asked.
    //
    // **This is the road it matters most on.** A wallet is asked for an
    // address and not for a billing contact, so a name arriving at all is
    // unproved here, and this is the shape a live wallet buyer may always have.
    expect($order->refresh()->customer->name)->toBeNull();
});

it('everything downstream of settlement works for a wallet identity', function () {
    // A pass, because the claim link follows the Viewing Room rather than the
    // payment. What this test is about is that the wallet road reaches the same
    // downstream as the hosted one, not which product was bought.
    $order = unownedOrderWorth(2500, 'pi_wallet', Product::factory()->pass());
    stripeWithNoSessionAndACharge(['email' => 'jennifer@example.com', 'name' => 'Jennifer']);

    app(RecordStripePayment::class)(paymentFor($order, 'pi_wallet'));

    // The receipt, and the invitation to choose a password for the pass that
    // was just granted.
    Mail::assertQueued(OrderPaid::class);
    Mail::assertQueued(SetPasswordLink::class);
});

it('settles a wallet payment exactly once however many times it is redelivered', function () {
    // A pass, so that the claim link is in play at all and the redelivery has
    // two messages to be counted against rather than one.
    $order = unownedOrderWorth(2500, 'pi_wallet', Product::factory()->pass());
    stripeWithNoSessionAndACharge(['email' => 'jennifer@example.com', 'name' => 'Jennifer']);

    expect(app(RecordStripePayment::class)(paymentFor($order, 'pi_wallet')))->toBeTrue()
        ->and(app(RecordStripePayment::class)(paymentFor($order, 'pi_wallet')))->toBeFalse()
        ->and(Customer::count())->toBe(1);

    Mail::assertQueued(OrderPaid::class, 1);
    Mail::assertQueued(SetPasswordLink::class, 1);
});

/**
 * **The line that would have fired on every wallet payment ever taken**, and
 * now fires only when both sources are genuinely empty.
 *
 * It names both, deliberately. It is the line that appears if `emailRequired`
 * turns out not to reach the charge, so it should say which two places were
 * looked in rather than sending the reader to one of two resolvers at random.
 */
it('leaves the order pending and names both sources when neither has anybody', function () {
    Log::spy();

    $order = unownedOrderWorth(2500, 'pi_wallet');
    stripeWithNoSessionAndACharge(null);

    expect(app(RecordStripePayment::class)(paymentFor($order, 'pi_wallet')))->toBeFalse();

    $order->refresh();

    // **The money is not lost and neither is the order.** It holds the intent
    // that paid for it, so orders:reconcile comes back to it.
    expect($order->status)->toBe(OrderStatus::Pending)
        ->and($order->customer_id)->toBeNull()
        ->and($order->charged_amount)->toBe(2500);

    Mail::assertNothingQueued();

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => str_contains($message, 'Checkout Session')
            && str_contains($message, 'billing details')
            && $context['order_id'] === $order->id
    );
});

it('leaves the order pending when the charge carries no email', function () {
    $order = unownedOrderWorth(2500, 'pi_wallet');
    stripeWithNoSessionAndACharge(['email' => null, 'name' => 'Jennifer']);

    expect(app(RecordStripePayment::class)(paymentFor($order, 'pi_wallet')))->toBeFalse()
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);

    // A name with no address cannot make an account, because the address is
    // what identifies one.
    expect(Customer::count())->toBe(0);
});

/**
 * **Nothing on this road throws.** Settlement is reached from a webhook Stripe
 * redelivers forever on anything that is not a 2xx, so a Stripe outage while
 * reading the charge must leave the payment recordable rather than raise.
 */
it('leaves the order pending when Stripe cannot be asked about the charge', function () {
    Log::spy();

    $order = unownedOrderWorth(2500, 'pi_wallet');

    $intents = Mockery::mock(PaymentIntentService::class);
    $intents->shouldReceive('retrieve')->andThrow(ApiConnectionException::factory('Stripe could not be reached'));

    substitutedStripe($intents, stripeSessions([]));

    expect(app(RecordStripePayment::class)(paymentFor($order, 'pi_wallet')))->toBeFalse()
        ->and($order->refresh()->status)->toBe(OrderStatus::Pending);

    // Loud, because somebody has been charged and this is the only thing
    // standing between them and their reading. Once for the unreachable Stripe
    // and once for the order that was left pending because of it.
    Log::shouldHaveReceived('error')->twice();
});

/**
 * A later attempt settles it, which is what `orders:reconcile` is for. The
 * charge is written the moment the payment is confirmed, so an attempt that
 * raced the confirmation finds it on the next sweep.
 */
it('settles on a later attempt once the charge can be read', function () {
    $order = unownedOrderWorth(2500, 'pi_wallet');
    stripeWithNoSessionAndACharge(null);

    expect(app(RecordStripePayment::class)(paymentFor($order, 'pi_wallet')))->toBeFalse();

    stripeWithNoSessionAndACharge(['email' => 'jennifer@example.com', 'name' => 'Jennifer']);

    expect(app(RecordStripePayment::class)(paymentFor($order, 'pi_wallet')))->toBeTrue()
        ->and($order->refresh()->status)->toBe(OrderStatus::Paid);
});
