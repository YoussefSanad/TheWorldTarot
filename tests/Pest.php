<?php

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Payments\StripePayment;
use App\Support\Locales;
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
 */
function unownedOrderWorth(int $amount, string $intentId = 'pi_hosted'): Order
{
    return Order::factory()
        ->forProduct(Product::factory()->create([
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
