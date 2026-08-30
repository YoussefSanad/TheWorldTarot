<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentInstructionType;
use App\Enums\ProductKey;
use App\Models\Order;
use App\Models\Product;
use App\Payments\OrderCannotBeCharged;
use App\Services\Payments\StripeWallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;

uses(RefreshDatabase::class);

/**
 * What this application asks Stripe for when the customer presses a wallet.
 *
 * **The substituted client agrees with this code whatever this code does**, so
 * nothing here says Stripe accepts any of it, and nothing here says a wallet
 * sheet ever opens. What it pins is the decisions that are ours: which amount,
 * which currency, that the order id travels on the intent rather than beside
 * it, that card is named explicitly, and that the intent id is written back
 * before the secret goes out.
 *
 * The authority on whether Stripe accepts it is the captured signature vector
 * on the webhook and the walk in tests/Staging. The authority on whether a
 * wallet collects an email is a real device, which is issue #44.
 *
 * See docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md.
 */
function stripeMintingIntents(array &$sent, string $intentId = 'pi_wallet'): void
{
    $intents = Mockery::mock(PaymentIntentService::class);

    $intents->shouldReceive('create')->andReturnUsing(
        function (array $params) use (&$sent, $intentId): PaymentIntent {
            $sent[] = $params;

            return PaymentIntent::constructFrom([
                'id' => $intentId.'_'.count($sent),
                'object' => 'payment_intent',
                'client_secret' => $intentId.'_'.count($sent).'_secret_shh',
            ]);
        },
    );

    // **No Checkout Session service at all.** This road has no Session, so
    // reaching for one has to fail the test rather than quietly find nothing.
    substitutedStripe($intents);
}

/**
 * **The stored order's total, never the catalogue and never a Stripe Price.**
 * Jennifer edits prices, and an amount read from anywhere else is an amount
 * that can move between the order and the payment.
 */
it('charges the total the customer was shown, in minor units', function () {
    $sent = [];
    stripeMintingIntents($sent);

    $order = orderWorth(7500);

    app(StripeWallet::class)->begin($order);

    expect($sent)->toHaveCount(1)
        ->and($sent[0]['amount'])->toBe(7500)
        ->and($sent[0]['amount'])->toBe($order->total_amount);
});

it('charges the captured price, not what the product costs today', function () {
    $sent = [];
    stripeMintingIntents($sent);

    $product = Product::factory()->create(['price_usd' => 7500]);
    $order = Order::factory()->forProduct($product)->create();

    // Jennifer edits the price between the order and the payment. What the
    // customer owes was decided when they ordered and cannot move.
    $product->forceFill(['price_usd' => 9900])->save();

    app(StripeWallet::class)->begin($order->refresh());

    expect($sent[0]['amount'])->toBe(7500);
});

it('charges in the currency the order was placed in, lowercased for stripe', function () {
    $sent = [];
    stripeMintingIntents($sent);

    app(StripeWallet::class)->begin(orderWorth(1900, 'GBP'));

    expect($sent[0]['currency'])->toBe('gbp');
});

/**
 * **On the intent itself, which on this road is the only place there is.**
 * Settlement is `payment_intent.succeeded` keyed by `metadata.order_id`, and
 * the hosted road reaches the same field the long way round, through
 * `payment_intent_data`. Both arrive at one webhook.
 */
it('sends the order id along, which is how the payment finds its way back', function () {
    $sent = [];
    stripeMintingIntents($sent);

    $order = orderWorth(2500);

    app(StripeWallet::class)->begin($order);

    // A string, because Stripe metadata values are strings.
    expect($sent[0]['metadata'])->toBe(['order_id' => (string) $order->id]);
});

/**
 * **Card alone, set explicitly, and not `automatic_payment_methods`.**
 *
 * Apple Pay and Google Pay are card payment methods, so the element loses
 * nothing by it. What it buys is that the set cannot grow from a Dashboard
 * nobody is watching, which is the same reasoning `adaptive_pricing` carries in
 * StripeCheckout, and it makes ADR 0003's `return_url` paragraph literally true
 * rather than approximately true: with card alone the only redirect in play is
 * 3D Secure returning to where it started.
 */
it('names card explicitly rather than letting a dashboard decide', function () {
    $sent = [];
    stripeMintingIntents($sent);

    app(StripeWallet::class)->begin(orderWorth(2500));

    expect($sent[0]['payment_method_types'])->toBe(['card'])
        ->and($sent[0])->not->toHaveKey('automatic_payment_methods');
});

/**
 * **OrderPaid is the receipt.** Asking Stripe to email one as well means two
 * receipts for one payment, which is a support conversation rather than a
 * courtesy.
 */
it('never asks stripe to send a receipt', function () {
    $sent = [];
    stripeMintingIntents($sent);

    app(StripeWallet::class)->begin(orderWorth(2500));

    expect($sent[0])->not->toHaveKey('receipt_email');
});

/**
 * **Easier here than on the hosted road, rather than harder.** A PaymentIntent
 * has its id at creation, with no Session in between.
 */
it('writes the payment back to the order before handing out the secret', function () {
    $sent = [];
    stripeMintingIntents($sent, intentId: 'pi_written');

    $order = orderWorth(2500);

    $instruction = app(StripeWallet::class)->begin($order);

    expect($order->fresh()->stripe_payment_intent_id)->toBe('pi_written_1')
        ->and($instruction->type)->toBe(PaymentInstructionType::ClientSecret)
        ->and($instruction->clientSecret)->toBe('pi_written_1_secret_shh');
});

/**
 * A declined card is a retry rather than a dead end, and an order never
 * expires, so a second call mints a second intent and the order remembers the
 * newest. An older intent that somehow succeeded is still found through its own
 * metadata, and both roads reach MarkOrderPaid.
 */
it('mints a fresh intent each time, and remembers the newest payment', function () {
    $sent = [];
    stripeMintingIntents($sent);

    $order = orderWorth(2500);

    $first = app(StripeWallet::class)->begin($order);
    $second = app(StripeWallet::class)->begin($order->refresh());

    expect($sent)->toHaveCount(2)
        ->and($second->clientSecret)->not->toBe($first->clientSecret)
        ->and($order->fresh()->stripe_payment_intent_id)->toBe('pi_wallet_2');
});

/**
 * **No idempotency key across calls**, deliberately, because a second call is
 * meant to mint a fresh intent. A duplicate from a network retry inside one
 * call is harmless: both carry the same order id, only one can succeed, and
 * MarkOrderPaid refuses the second transition.
 */
it('does not pin the two calls together with an idempotency key', function () {
    $sent = [];
    stripeMintingIntents($sent);

    app(StripeWallet::class)->begin(orderWorth(2500));

    expect($sent[0])->not->toHaveKey('idempotency_key');
});

/**
 * **There is nowhere to send anybody**, so there is nothing to come back from.
 * `return_url` belongs to `stripe.confirmPayment` on the frontend's own page
 * and is built there. Nothing caller-supplied reaches this application on this
 * road, and no address is minted on it.
 */
it('sends nobody anywhere, whatever reading page they came from', function () {
    $sent = [];
    stripeMintingIntents($sent);

    app(StripeWallet::class)->begin(orderWorth(2500), ProductKey::MonthAhead);

    expect($sent[0])->not->toHaveKey('return_url')
        ->and($sent[0])->not->toHaveKey('success_url')
        ->and($sent[0])->not->toHaveKey('cancel_url');
});

/*
| The guard both gateways call, observed through this one. It lives in
| App\Payments\RefuseAnythingButAPendingOrder rather than inside either, and
| RefuseAnythingButAPendingOrderTest is where its own rules are pinned. What
| these prove is that this gateway runs it before it asks Stripe for anything.
*/

it('refuses to charge for an order that is not pending', function () {
    $sent = [];
    stripeMintingIntents($sent);

    $order = orderWorth(2500);
    $order->forceFill(['status' => OrderStatus::Paid, 'paid_at' => now()])->save();

    expect(fn () => app(StripeWallet::class)->begin($order))
        ->toThrow(OrderCannotBeCharged::class);

    expect($sent)->toBe([]);
});

it('refuses to charge an order whose total disagrees with its own lines', function () {
    $sent = [];
    stripeMintingIntents($sent);

    $order = orderWorth(2500);
    $order->forceFill(['total_amount' => 100])->save();

    expect(fn () => app(StripeWallet::class)->begin($order->refresh()))
        ->toThrow(OrderCannotBeCharged::class);

    expect($sent)->toBe([]);
});

it('logs the mismatch as an error, because it can only be a defect upstream', function () {
    Log::spy();

    $sent = [];
    stripeMintingIntents($sent);

    $order = orderWorth(2500);
    $order->forceFill(['total_amount' => 100])->save();

    try {
        app(StripeWallet::class)->begin($order->refresh());
    } catch (OrderCannotBeCharged) {
        // The refusal is the test above. This one is about what it left behind.
    }

    Log::shouldHaveReceived('error')->once()->withArgs(
        fn (string $message, array $context): bool => $context['order_id'] === $order->id
            && $context['total_amount'] === 100
            && $context['total_of_lines'] === 2500
    );
});
