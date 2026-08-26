<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentInstructionType;
use App\Models\Order;
use App\Models\Product;
use App\Payments\OrderCannotBeCharged;
use App\Services\Payments\StripeCheckout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;

uses(RefreshDatabase::class);

/**
 * What this application asks Stripe for.
 *
 * **The substituted client agrees with this code whatever this code does**, so
 * nothing here says Stripe accepts any of it. What it does pin is the decisions
 * that are ours: which amount, which currency, that the order id travels with
 * the payment, that no second receipt is asked for, and that the intent id is
 * written back before the secret goes out.
 *
 * The authority on whether Stripe accepts it is the captured signature vector
 * on the webhook and the walk against a deployed environment.
 */
function stripeAccepting(array &$sent, string $intentId = 'pi_3Abc', string $secret = 'pi_3Abc_secret_xyz'): void
{
    $intents = Mockery::mock(PaymentIntentService::class);

    $intents->shouldReceive('create')->andReturnUsing(
        function (array $params) use (&$sent, $intentId, $secret): PaymentIntent {
            $sent[] = $params;

            return PaymentIntent::constructFrom([
                'id' => $intentId.'_'.count($sent),
                'client_secret' => $secret.'_'.count($sent),
            ]);
        },
    );

    substitutedStripe($intents);
}

function orderWorth(int $amount, string $currency = 'USD'): Order
{
    return Order::factory()
        ->in($currency)
        ->forProduct(Product::factory()->create([
            'price_usd' => $amount,
            'price_eur' => $amount,
            'price_gbp' => $amount,
        ]))
        ->create();
}

it('charges the total the customer was shown, in minor units', function () {
    $sent = [];
    stripeAccepting($sent);

    $order = orderWorth(7500);

    app(StripeCheckout::class)->begin($order);

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['amount'])->toBe(7500)
        ->and($sent[0]['amount'])->toBe($order->total_amount);
});

it('charges the captured price, not what the product costs today', function () {
    $sent = [];
    stripeAccepting($sent);

    $product = Product::factory()->create(['price_usd' => 7500]);
    $order = Order::factory()->forProduct($product)->create();

    // Jennifer edits the price between the order and the payment. What the
    // customer owes was decided when they ordered and cannot move.
    $product->forceFill(['price_usd' => 9900])->save();

    app(StripeCheckout::class)->begin($order->refresh());

    expect($sent[0]['amount'])->toBe(7500);
});

it('charges in the currency the order was placed in, lowercased for stripe', function () {
    $sent = [];
    stripeAccepting($sent);

    app(StripeCheckout::class)->begin(orderWorth(1900, 'GBP'));

    expect($sent[0]['currency'])->toBe('gbp');
});

it('sends the order id along, which is how the payment finds its way back', function () {
    $sent = [];
    stripeAccepting($sent);

    $order = orderWorth(2500);

    app(StripeCheckout::class)->begin($order);

    // A string, because Stripe metadata values are strings.
    expect($sent[0]['metadata'])->toBe(['order_id' => (string) $order->id]);
});

/**
 * **OrderPaid is the receipt.** Asking Stripe to email one as well means two
 * receipts for one payment, which is a support conversation rather than a
 * courtesy.
 */
it('never asks stripe to send a receipt', function () {
    $sent = [];
    stripeAccepting($sent);

    app(StripeCheckout::class)->begin(orderWorth(2500));

    expect($sent[0])->not->toHaveKey('receipt_email');
});

it('writes the payment back to the order before handing out the secret', function () {
    $sent = [];
    stripeAccepting($sent, intentId: 'pi_written');

    $order = orderWorth(2500);

    $instruction = app(StripeCheckout::class)->begin($order);

    // Written at the moment the intent is created rather than when it succeeds,
    // because a lost notification is exactly what the sweep exists for and by
    // then there is nothing left to write this from.
    expect($order->fresh()->stripe_payment_intent_id)->toBe('pi_written_1')
        ->and($instruction->type)->toBe(PaymentInstructionType::ClientSecret)
        ->and($instruction->clientSecret)->toBe('pi_3Abc_secret_xyz_1');
});

it('mints a fresh payment each time, and remembers the newest', function () {
    $sent = [];
    stripeAccepting($sent);

    $order = orderWorth(2500);

    $first = app(StripeCheckout::class)->begin($order);
    $second = app(StripeCheckout::class)->begin($order->refresh());

    expect($sent)->toHaveCount(2)
        ->and($second->clientSecret)->not->toBe($first->clientSecret)
        ->and($order->fresh()->stripe_payment_intent_id)->toBe('pi_3Abc_2');
});

/**
 * **No idempotency key across calls**, deliberately, because a second call is
 * meant to mint a fresh intent. A duplicate from a network retry inside one
 * call is harmless: both carry the same order id, only one can succeed, and
 * MarkOrderPaid refuses the second transition.
 */
it('does not pin the two calls together with an idempotency key', function () {
    $sent = [];
    stripeAccepting($sent);

    $order = orderWorth(2500);
    app(StripeCheckout::class)->begin($order);

    expect($sent[0])->not->toHaveKey('idempotency_key');
});

it('refuses to charge for an order that is not pending', function () {
    $sent = [];
    stripeAccepting($sent);

    $order = orderWorth(2500);
    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    expect(fn () => app(StripeCheckout::class)->begin($order))
        ->toThrow(OrderCannotBeCharged::class);

    expect($sent)->toBe([]);
});

it('refuses to charge an order whose total disagrees with its own lines', function () {
    $sent = [];
    stripeAccepting($sent);

    $order = orderWorth(2500);
    $order->forceFill(['total_amount' => 100])->save();

    expect(fn () => app(StripeCheckout::class)->begin($order->refresh()))
        ->toThrow(OrderCannotBeCharged::class);

    expect($sent)->toBe([]);
});

/**
 * **And says so loudly**, decided 26 August 2026 as finding 14 of the phase B
 * review.
 *
 * This refusal is beyond what the spec asked for, and the reason it stays is
 * that it cannot fire innocently: prices are tax inclusive by decision, so
 * nothing rounds and a mismatch means something wrote an order badly. Refusing
 * silently would protect this customer and leave the next one to find it again.
 */
it('logs the mismatch as an error, because it can only be a defect upstream', function () {
    Log::spy();

    $sent = [];
    stripeAccepting($sent);

    $order = orderWorth(2500);
    $order->forceFill(['total_amount' => 100])->save();

    try {
        app(StripeCheckout::class)->begin($order->refresh());
    } catch (OrderCannotBeCharged) {
        // The refusal is the test above. This one is about what it left behind.
    }

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $context['order_id'] === $order->id
            && $context['total_amount'] === 100
            && $context['total_of_lines'] === 2500
    );
});
