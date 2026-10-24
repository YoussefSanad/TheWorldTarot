<?php

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\ProductKey;
use App\Http\Resources\Api\V1\PaymentInstructionResource;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Payments\PaymentInstruction;
use App\Services\Payments\ManualSettlement;
use App\Services\Payments\StripeCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Stripe\Checkout\Session;
use Stripe\Service\Checkout\SessionService;
use Stripe\Service\PaymentIntentService;

uses(RefreshDatabase::class);

/**
 * The pay endpoint, driven through HTTP against a **fake gateway**.
 *
 * **The fake is the danger in this file, not the safety.** It proves this
 * controller and it proves nothing whatsoever about Stripe: it agrees with
 * whatever the controller does, exactly as a fake `VideoPlayback` would have
 * agreed with playback that had never worked. Nothing here may later be read as
 * evidence that Stripe accepts what we send. The captured signature vector on
 * the webhook and the walk against a deployed environment carry that.
 *
 * See .claude/external-services.md.
 */
class FakeCheckout implements PaymentGateway
{
    /** @var array<int, int> Every order a payment was started for, in order. */
    public static array $started = [];

    /** @var array<int, ?ProductKey> Where each of those was told to return to. */
    public static array $returnTo = [];

    public function begin(Order $order, ?ProductKey $returnTo = null): PaymentInstruction
    {
        self::$started[] = $order->id;
        self::$returnTo[] = $returnTo;

        return PaymentInstruction::redirect(
            'https://checkout.stripe.com/c/pay/cs_fake_'.$order->id.'_'.count(self::$started),
        );
    }
}

/**
 * The other kind of answer, which `FakeCheckout` above cannot give.
 *
 * **Here so that the endpoint's `client_secret` road is walked by something**,
 * rather than proved only by `PaymentInstructionTest` on the value object and
 * by a staging walk that is skipped unless somebody sets `STAGING_WALLET=1`.
 * What is being tested is this endpoint: that it serialises the second kind of
 * instruction, and that it withholds it from caches exactly as it does an
 * address. The gateway behind it is `StripeWalletTest`'s business.
 */
class FakeWallet implements PaymentGateway
{
    public function begin(Order $order, ?ProductKey $returnTo = null): PaymentInstruction
    {
        return PaymentInstruction::clientSecret('pi_fake_'.$order->id.'_secret_wpvxs2');
    }
}

beforeEach(function () {
    FakeCheckout::$started = [];
    FakeCheckout::$returnTo = [];

    config()->set('payments.methods', [
        'manual' => ManualSettlement::class,
        'card' => FakeCheckout::class,
        PaymentMethod::StripeWallet->value => FakeWallet::class,
    ]);
    config()->set('payments.default', 'card');
});

function payableOrder(?Customer $customer = null): Order
{
    return Order::factory()
        ->for($customer ?? Customer::factory()->create())
        ->forProduct(Product::factory()->create(['price_usd' => 7500]))
        ->create();
}

it('mints a payment for a pending order and says which kind of answer it is', function () {
    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay")
        ->assertOk()
        ->assertJsonPath('type', 'redirect')
        ->assertJsonPath('redirect_url', fn (?string $url): bool => str_starts_with((string) $url, 'https://checkout.stripe.com/'));

    expect(FakeCheckout::$started)->toBe([$order->id]);
});

it('answers nothing to pay for an order already paid, and creates nothing', function () {
    $order = payableOrder();
    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay")
        ->assertOk()
        ->assertExactJson(['type' => 'nothing_to_pay']);

    // **The property that makes being charged twice impossible.** Nothing was
    // started, so there is no second payment for anything to succeed against.
    expect(FakeCheckout::$started)->toBe([]);
});

it('answers nothing to pay when the method collects nothing', function () {
    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay", ['method' => 'manual'])
        ->assertOk()
        ->assertExactJson(['type' => 'nothing_to_pay']);
});

it('mints a fresh payment on a second call, so a decline is a retry', function () {
    $order = payableOrder();

    $first = $this->postJson("/api/v1/orders/{$order->pay_token}/pay")->assertOk();
    $second = $this->postJson("/api/v1/orders/{$order->pay_token}/pay")->assertOk();

    expect(FakeCheckout::$started)->toBe([$order->id, $order->id])
        ->and($second->json('redirect_url'))->not->toBe($first->json('redirect_url'));

    // And no second order was built to carry it.
    expect(Order::count())->toBe(1);
});

/**
 * A Checkout Session expires after 24 hours and an order never does, which is
 * why calling again is how an abandoned checkout resumes.
 */
it('says the order is still there tomorrow', function () {
    $order = payableOrder();

    $this->travel(3)->days();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay")
        ->assertOk()
        ->assertJsonPath('type', 'redirect');
});

/*
| Not being able to reach somebody else's order.
*/

it('does not know an unknown token', function () {
    payableOrder();

    $this->postJson('/api/v1/orders/'.Order::mintPayToken().'/pay')
        ->assertNotFound();

    expect(FakeCheckout::$started)->toBe([]);
});

it('refuses a signed in customer somebody else"s order', function () {
    $order = payableOrder();
    $somebodyElse = Customer::factory()->create();

    $this->actingAs($somebodyElse, 'sanctum')
        ->postJson("/api/v1/orders/{$order->pay_token}/pay")
        ->assertNotFound();

    expect(FakeCheckout::$started)->toBe([]);
});

/**
 * **An order belonging to nobody is not somebody else's.** Checkout collects
 * the buyer's email after the order exists, so `customer_id` stays null until
 * the webhook learns who paid. Somebody who signs in or registers mid-checkout
 * and then pays is in exactly that window, and refusing them would leave them
 * unable to pay for the order they hold the token for.
 */
it('lets a signed in customer pay for an order that is waiting for its customer', function () {
    $order = payableOrder();
    $order->forceFill(['customer_id' => null])->save();

    $this->actingAs(Customer::factory()->create(), 'sanctum')
        ->postJson("/api/v1/orders/{$order->pay_token}/pay")
        ->assertOk()
        ->assertJsonPath('type', 'redirect');

    expect(FakeCheckout::$started)->toBe([$order->id]);
});

it('lets the customer who placed it pay for it while signed in', function () {
    $customer = Customer::factory()->create();
    $order = payableOrder($customer);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/orders/{$order->pay_token}/pay")
        ->assertOk()
        ->assertJsonPath('type', 'redirect');
});

it('lets a guest holding the token pay, because that is the normal case', function () {
    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay")->assertOk();
});

it('tells a guesser nothing about the order behind a token', function () {
    $order = payableOrder();

    $body = $this->postJson("/api/v1/orders/{$order->pay_token}/pay")->assertOk()->json();

    // Not the amount, not the email, not the id. Only what to do next.
    expect(array_keys($body))->toBe(['type', 'redirect_url']);
});

/*
| The method, chosen per payment.
*/

it('refuses a method this environment does not offer, naming the field', function () {
    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay", ['method' => 'gift_code'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('method');

    expect(FakeCheckout::$started)->toBe([]);
});

it('refuses a typo rather than falling through to something that charges', function () {
    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay", ['method' => 'crad'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('method');

    expect(FakeCheckout::$started)->toBe([]);
});

/*
| Shape and plumbing.
*/

/**
 * Finding 6 of the phase B review. The endpoint answers through
 * `PaymentInstructionResource` as .claude/api.md requires, and the body it
 * produces is byte for byte what the raw JSON was.
 *
 * The resource is unwrapped, so nothing here should ever see a `data` key. The
 * guesser test above pins the same thing from the other direction, by naming
 * every key that may appear.
 */
it('answers through a resource without changing what the frontend receives', function () {
    $order = payableOrder();

    $response = $this->postJson('/api/v1/orders/'.$order->pay_token.'/pay');

    expect($response->json())
        ->toBe(PaymentInstructionResource::make(
            PaymentInstruction::redirect($response->json('redirect_url'))
        )->toArray(request()))
        ->and($response->json())->not->toHaveKey('data');
});

/**
 * The address of a Checkout Session is a credential with this order's money
 * attached, exactly as the client secret it replaced was.
 */
it('refuses to be cached, because the address is a credential', function () {
    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
});

/**
 * **A client secret is a credential with this order's money attached**, which
 * is the same reason the address above is one, so the wallet road is held to
 * the header as well rather than inheriting it by assumption.
 *
 * The body is pinned to exactly two keys for the reason the guesser test pins
 * the other shape: an answer that leaked the order's price or its buyer would
 * hand a pay token holder something the token does not entitle them to.
 */
it('answers a client secret for the wallet, and refuses to cache that either', function () {
    $order = payableOrder();

    $response = $this->postJson("/api/v1/orders/{$order->pay_token}/pay", [
        'method' => PaymentMethod::StripeWallet->value,
    ]);

    $response->assertOk()
        ->assertJsonPath('type', 'client_secret')
        ->assertJsonPath('client_secret', 'pi_fake_'.$order->id.'_secret_wpvxs2')
        ->assertHeader('Cache-Control', 'no-store, private');

    expect(array_keys($response->json()))->toBe(['type', 'client_secret']);
});

it('carries no locale segment, since paying is not content', function () {
    $order = payableOrder();

    $this->postJson("/api/v1/en/orders/{$order->pay_token}/pay")->assertNotFound();
});

/**
 * Its own throttle key prefix, per the rule at the top of routes/api.php. A
 * shared bucket is not theoretical here: it was found on staging on 13 August
 * 2026, where six orders refused the next password reset.
 */
it('counts its own requests rather than sharing a bucket', function () {
    $middleware = collect(Route::getRoutes()->getByName('orders.pay')->gatherMiddleware())
        ->first(fn (string $m): bool => str_starts_with($m, 'throttle:'));

    expect($middleware)->toBeString();

    [, $arguments] = explode(':', $middleware, 2);

    expect(explode(',', $arguments))->toHaveCount(3)
        ->and(explode(',', $arguments)[2])->toBe('orders-pay');
});

/*
| Where a cancelled checkout comes back to, named and never composed.
*/

/**
 * **`return_to` is a product key, not a path and not a URL.** `ProductKey`'s
 * values are already the frontend's reading-page slugs, so an allowlisted enum
 * means no string from the caller reaches an address at all.
 */
it('passes the reading the checkout started from to the gateway', function () {
    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay", ['return_to' => 'month-ahead'])
        ->assertOk();

    expect(FakeCheckout::$returnTo)->toBe([ProductKey::MonthAhead]);
});

it('starts a payment that named no reading, because it is optional', function () {
    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay")->assertOk();

    expect(FakeCheckout::$returnTo)->toBe([null]);
});

it('refuses anything that is not a reading, and never falls back to one', function (string $returnTo) {
    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay", ['return_to' => $returnTo])
        ->assertStatus(422)
        ->assertJsonValidationErrors('return_to');

    expect(FakeCheckout::$started)->toBe([]);
})->with([
    'a URL somebody else owns' => 'https://evil.example.com/',
    'a path' => '/readings/month-ahead/',
    'a traversal' => '../../admin',
    'a reading that does not exist' => 'two-card',
    'nothing at all' => '',
]);

/*
| The cancel address Stripe is actually given, issue #94. Through the real
| StripeCheckout with Stripe's client substituted, because the fake gateway
| above never builds an address and so cannot see the mapping.
*/

function payStripeAccepting(array &$sent): void
{
    $sessions = Mockery::mock(SessionService::class);

    $sessions->shouldReceive('create')->andReturnUsing(
        function (array $params) use (&$sent): Session {
            $sent[] = $params;

            return Session::constructFrom([
                'id' => 'cs_test_'.count($sent),
                'url' => 'https://checkout.stripe.com/c/pay/cs_test_'.count($sent),
                'payment_intent' => 'pi_3Abc_'.count($sent),
            ]);
        },
    );

    substitutedStripe(Mockery::mock(PaymentIntentService::class), $sessions);
}

it('gives stripe the viewing room as the cancel address for the pass', function () {
    config()->set('frontend.url', 'https://theworldtarot.com');
    config()->set('payments.methods.card', StripeCheckout::class);

    $sent = [];
    payStripeAccepting($sent);

    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay", ['return_to' => 'viewing-room-pass'])
        ->assertOk()
        ->assertJsonPath('type', 'redirect');

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['cancel_url'])->toBe('https://theworldtarot.com/viewing-room/');
});

it('gives stripe the reading page as the cancel address for each reading', function (ProductKey $reading) {
    config()->set('frontend.url', 'https://theworldtarot.com');
    config()->set('payments.methods.card', StripeCheckout::class);

    $sent = [];
    payStripeAccepting($sent);

    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay", ['return_to' => $reading->value])
        ->assertOk();

    expect($sent[0]['cancel_url'])->toBe('https://theworldtarot.com/readings/'.$reading->value.'/');
})->with(fn () => ProductKey::readings());

it('refuses the rush add-on as a place to return to, and starts nothing', function () {
    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay", ['return_to' => 'rush-24h'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('return_to');

    expect(FakeCheckout::$started)->toBe([]);
});

it('refuses an unknown key as a place to return to', function () {
    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay", ['return_to' => 'viewing-room'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('return_to');

    expect(FakeCheckout::$started)->toBe([]);
});

it('ignores return_to when the method collects nothing', function () {
    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay", [
        'method' => 'manual',
        'return_to' => 'viewing-room-pass',
    ])->assertOk()->assertExactJson(['type' => 'nothing_to_pay']);
});

it('ignores return_to when the wallet method answers with a client secret', function () {
    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay", [
        'method' => PaymentMethod::StripeWallet->value,
        'return_to' => 'viewing-room-pass',
    ])->assertOk()->assertJsonPath('type', 'client_secret');
});
