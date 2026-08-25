<?php

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Payments\PaymentInstruction;
use App\Services\Payments\ManualSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

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

    public function begin(Order $order): PaymentInstruction
    {
        self::$started[] = $order->id;

        return PaymentInstruction::clientSecret('pi_fake_'.$order->id.'_secret_'.count(self::$started));
    }
}

beforeEach(function () {
    FakeCheckout::$started = [];

    config()->set('payments.methods', [
        'manual' => ManualSettlement::class,
        'card' => FakeCheckout::class,
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
        ->assertJsonPath('type', 'client_secret')
        ->assertJsonPath('client_secret', fn (?string $secret): bool => str_starts_with((string) $secret, 'pi_fake_'));

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
        ->and($second->json('client_secret'))->not->toBe($first->json('client_secret'));

    // And no second order was built to carry it.
    expect(Order::count())->toBe(1);
});

it('says the order is still there tomorrow', function () {
    $order = payableOrder();

    $this->travel(3)->days();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay")
        ->assertOk()
        ->assertJsonPath('type', 'client_secret');
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

it('lets the customer who placed it pay for it while signed in', function () {
    $customer = Customer::factory()->create();
    $order = payableOrder($customer);

    $this->actingAs($customer, 'sanctum')
        ->postJson("/api/v1/orders/{$order->pay_token}/pay")
        ->assertOk()
        ->assertJsonPath('type', 'client_secret');
});

it('lets a guest holding the token pay, because that is the normal case', function () {
    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay")->assertOk();
});

it('tells a guesser nothing about the order behind a token', function () {
    $order = payableOrder();

    $body = $this->postJson("/api/v1/orders/{$order->pay_token}/pay")->assertOk()->json();

    // Not the amount, not the email, not the id. Only what to do next.
    expect(array_keys($body))->toBe(['type', 'client_secret']);
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

it('refuses to be cached, because a client secret is a credential', function () {
    $order = payableOrder();

    $this->postJson("/api/v1/orders/{$order->pay_token}/pay")
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
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
