<?php

use App\Enums\ProductKey;
use App\Models\Customer;
use App\Models\Gift;
use App\Models\Order;
use App\Models\Product;
use App\Payments\PaidBy;
use App\Payments\StripePayment;
use App\Support\Locales;
use Database\Factories\ProductFactory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Stripe\Collection;
use Stripe\PaymentIntent;
use Stripe\Service\Checkout\CheckoutServiceFactory;
use Stripe\Service\Checkout\SessionService;
use Stripe\Service\PaymentIntentService;
use Stripe\StripeClient;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->beforeEach(function (): void {
        // The locale registry is read once and cached for the request. That
        // cache is static, so without this it survives from one test into the
        // next and a language looks live because an earlier test made it so.
        //
        // Production is unaffected, since each request starts a new process, but
        // a test that changes a locale after making a request still has to
        // forget it explicitly.
        Locales::forget();

        // **No test reaches a third party, and one that tries fails loudly.**
        //
        // A handful of files asked for this and most did not, which turned out
        // not to be theoretical. The queue runs inline here, so a job dispatched
        // from a model event runs inside the test that saved the model, and when
        // `promoteIncomingVideo` learned to delete the video it supersedes, the
        // promotion test began sending a real DELETE to the real Bunny library.
        // It stayed green, because the guid was a factory uuid, Bunny answered
        // 404, and a 404 counts as deleted. It only surfaced on a machine with
        // no `BUNNY_API_KEY`, where it then failed for entirely the wrong reason.
        //
        // Green while quietly talking to a live third party is the worst failure
        // mode this suite has, and it is the second time this project has hit
        // it. Global rather than per file, so it cannot be forgotten again.
        Http::preventStrayRequests();
    })
    ->in('Feature');

/**
 * The broker's token for a customer, as they receive it.
 *
 * **Here rather than in one test file, and named after neither of the two
 * things it becomes.** It was written twice, byte for byte, as `resetTokenFor`
 * in `PasswordResetTest` and `claimToken` in `SetPasswordTest`, and the fact
 * that those two bodies were identical is not a coincidence to tidy away. It is
 * the finding.
 *
 * A claim and a reset are different acts and CONTEXT.md keeps them apart:
 * claiming finishes an account that has never had a password, resetting
 * replaces one. **The token is not different.** There is one broker and one
 * `password_reset_tokens` row per customer, so whether a token is a claim link
 * or a reset link is decided entirely by which mail carries it, and minting
 * either one deletes the other. `33927f1` exists because of that.
 *
 * So calling it `claimToken` in one file taught the reader that a claim token
 * is a kind of token, which is the belief that makes B2 surprising.
 *
 * The table stores a hash. This readable form exists only in the mail.
 */
function passwordTokenFor(Customer $customer): string
{
    return Password::broker('customers')->createToken($customer);
}

/**
 * The Stripe client, substituted for one that answers however a test says.
 *
 * **`Http::preventStrayRequests()` above cannot see stripe-php**, which uses
 * curl directly rather than Laravel's client, so nothing stops a test in this
 * suite from reaching the real Stripe except this substitution being used
 * everywhere. That is the reason this lives here rather than in one test file:
 * the shape was built twice, in StripeCheckoutTest and ReconcileOrdersTest, and
 * a third copy is how one of them ends up talking to a live service.
 *
 * **It proves nothing about Stripe and is not evidence of anything.** A mock
 * agrees with the code under test whatever the code does, which is how M4
 * shipped video playback that had never once worked. The authority on Stripe is
 * the captured signature vector and the walk in tests/Staging. See
 * .claude/external-services.md.
 */
function substitutedStripe(PaymentIntentService $paymentIntents, ?SessionService $checkoutSessions = null): StripeClient
{
    $client = Mockery::mock(StripeClient::class);
    $client->paymentIntents = $paymentIntents;

    // **Only when a test asks for it.** Settlement reads the Checkout Session
    // to learn who paid, and a test that has not said what Stripe answers
    // should fail by reaching for something that is not there rather than by
    // quietly finding an empty list and calling it a buyer nobody named.
    if ($checkoutSessions instanceof SessionService) {
        $checkout = Mockery::mock(CheckoutServiceFactory::class);
        $checkout->sessions = $checkoutSessions;

        $client->checkout = $checkout;
    }

    app()->instance(StripeClient::class, $client);

    return $client;
}

/**
 * Stripe answering with the Checkout Session that paid for an order.
 *
 * `null` for `$email` is a Session that collected nothing, which is what a
 * cancelled or expired one looks like. No session at all is the empty list.
 *
 * @param  array<int, array{email: ?string, name: ?string}>  $sessions
 */
function stripeSessions(array $sessions, ?array &$asked = null): SessionService
{
    $service = Mockery::mock(SessionService::class);

    $service->shouldReceive('all')->andReturnUsing(
        function (array $params) use ($sessions, &$asked): Collection {
            $asked[] = $params;

            return Collection::constructFrom([
                'data' => array_map(fn (array $session): array => [
                    'customer_details' => [
                        'email' => $session['email'],
                        'name' => $session['name'],
                    ],
                ], $sessions),
            ]);
        },
    );

    return $service;
}

/**
 * Stripe answering with the charge behind the intent that paid.
 *
 * **The wallet's road to an identity.** An express checkout PaymentIntent has
 * no Checkout Session, so the email the wallet collected arrives on the
 * intent's own charge. `null` for `$billing` is an intent that has no charge,
 * which is what one nobody ever confirmed looks like.
 *
 * `$asked` is filled with the id and the parameters of every retrieve, because
 * the expansion is the whole call: an unexpanded `latest_charge` is a string
 * and carries no billing details at all.
 *
 * **It proves nothing about Stripe**, exactly as `stripeSessions` above proves
 * nothing. Whether a wallet actually populates `billing_details.email` is
 * issue #44's, against a real device. See .claude/external-services.md.
 *
 * @param  array{email: ?string, name: ?string}|null  $billing
 * @param  array<int, array{id: string, params: ?array<string, mixed>}>|null  $asked
 */
function stripeCharge(?array $billing, ?array &$asked = null): PaymentIntentService
{
    $service = Mockery::mock(PaymentIntentService::class);

    $service->shouldReceive('retrieve')->andReturnUsing(
        function (string $id, ?array $params = null) use ($billing, &$asked): PaymentIntent {
            $asked[] = ['id' => $id, 'params' => $params];

            return PaymentIntent::constructFrom([
                'id' => $id,
                'object' => 'payment_intent',
                'latest_charge' => $billing === null ? null : [
                    'object' => 'charge',
                    'billing_details' => [
                        'email' => $billing['email'],
                        'name' => $billing['name'],
                    ],
                ],
            ]);
        },
    );

    return $service;
}

/**
 * A product with no name left in it, in the read locale or in `en`.
 *
 * **Here rather than in one test file**, because the panel, the receipt and the
 * model all have to agree about what this is called, and a fixture built twice
 * is a fixture two suites can drift apart on. It was written in `OrderLineTest`
 * first.
 *
 * **Written as an empty string rather than cleared**, since `name` is
 * `jsonb NOT NULL` and both spellings resolve the same way. `.claude/translation.md`
 * section 6 has the rule this exists to exercise.
 *
 * `three-card` because its default name and its key are unmistakably different
 * strings, which is the whole question: `3 CARD READING` against `three-card`.
 */
function aProductWithNoNameLeft(): Product
{
    $product = Product::factory()->create(['key' => ProductKey::ThreeCard]);
    $product->setTranslation('name', 'en', '')->save();

    return $product;
}

/**
 * An order for one product, priced the same in every currency.
 *
 * **Here rather than in one test file**, since both Stripe gateways are handed
 * the same kind of order and the assertions that matter are about what each one
 * does with it. It was written in `StripeCheckoutTest` first.
 */
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

/**
 * An order nobody owns yet, holding the intent that is paying for it.
 *
 * **Here rather than in one test file**, because both roads produce exactly
 * this: the hosted page collects the buyer's email after the order exists, and
 * a wallet payment has no Session to collect it from at all. Settlement is
 * where either one acquires an identity, so the fixture the two suites need is
 * the same fixture. It was written twice, in `SettleAnUnownedOrderTest` and
 * `SettleAWalletPaymentTest`, and the copies differed only in this default.
 *
 * **Name the intent when the road matters.** It has to match what
 * `paymentFor()` is handed, since that is the id settlement looks the order up
 * against.
 *
 * **Name the product when what was bought matters.** A reading by default,
 * because the amount is what most of these tests are about and a reading is the
 * ordinary order. Hand it `Product::factory()->pass()` where the question is
 * what settlement says to somebody who has just bought their way in, since a
 * claim link now follows the Viewing Room rather than the payment. The amount
 * is stamped onto whatever it is given, so the two stay independent.
 */
function unownedOrderWorth(int $amount, string $intentId = 'pi_hosted', ?ProductFactory $product = null): Order
{
    return Order::factory()
        ->forProduct(($product ?? Product::factory())->create([
            'price_usd' => $amount,
            'price_eur' => $amount,
            'price_gbp' => $amount,
        ]))
        ->create([
            'customer_id' => null,
            'stripe_payment_intent_id' => $intentId,
        ]);
}

/**
 * A payment Stripe says succeeded, for an order, as settlement receives one.
 *
 * The webhook and the sweep both hand `RecordStripePayment` this shape, so a
 * test that wants to settle an order starts here whichever road minted it. It
 * was written in `SettleAnUnownedOrderTest` first, and the wallet road settles
 * through exactly the same value.
 */
function paymentFor(Order $order, string $intentId = 'pi_hosted'): StripePayment
{
    return new StripePayment(
        id: $intentId,
        orderId: $order->id,
        amount: $order->total_amount,
        currency: $order->currency,
        status: 'succeeded',
    );
}

/**
 * Who paid, for a test that calls `MarkOrderPaid` directly.
 *
 * **Always at the order's own total**, since `charged_amount` equals
 * `total_amount` for every order that becomes paid (CLAUDE.md section 4, "Prices
 * are tax inclusive"). A test that wants a different amount, to prove nothing is
 * overwritten, builds its own `PaidBy` rather than bending this one.
 *
 * **Name the intent when the question is which payment this is.** The default
 * is one intent, so a test that settles twice with it is a redelivery and not
 * a second payment.
 *
 * **Name the buyer when the order has no owner.** Step 5a: `PaidBy` carries the
 * customer the payment resolved, and `MarkOrderPaid` writes it as the owner of
 * an unowned order inside its lock. The default is the order's own customer,
 * which is right for every order somebody placed signed in. With neither, this
 * throws, so a test about an unowned order says who paid rather than passing
 * nobody by accident.
 */
function paidBy(Order $order, string $intent = 'pi_test', ?Customer $buyer = null): PaidBy
{
    $customerId = $buyer?->getKey() ?? $order->customer_id;

    if ($customerId === null) {
        throw new LogicException('paidBy() needs a buyer: this order has no customer, so the test must name who paid.');
    }

    return new PaidBy($intent, $order->total_amount, $customerId);
}

/**
 * A present somebody has been sent, on a real order line for a real product.
 *
 * **Here rather than in one test file**, because looking a code up, spending
 * it, and racing two requests at it are three files that need the identical
 * fixture and differ only in what they then do to it. It was written in
 * `LookUpAGiftTest` first.
 *
 * **Minted by default**, since `GiftFactory` is: a gift with no code is the
 * window between placement and settlement, and neither endpoint can reach one.
 * Hand `['code' => null]` where that window is the point.
 *
 * `month-ahead` because it is giftable and `one-card` is not. Which products
 * may be gifted is B5's and is enforced at checkout, so nothing here re-asks
 * it: by the time a gift row exists the question has been answered.
 *
 * @param  array<string, mixed>  $gift  Overrides for the present itself
 * @param  array<string, mixed>  $product  Overrides for what was bought
 */
function aGiftOf(array $gift = [], array $product = []): Gift
{
    $order = Order::factory()
        ->forProduct(Product::factory()->create([
            'key' => ProductKey::MonthAhead,
            ...$product,
        ]))
        ->create();

    return Gift::factory()->create([
        'order_line_id' => $order->lines->sole()->id,
        ...$gift,
    ]);
}
