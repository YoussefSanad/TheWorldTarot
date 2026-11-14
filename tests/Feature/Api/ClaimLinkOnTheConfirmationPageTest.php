<?php

use App\Actions\ClaimAccount;
use App\Actions\MarkOrderPaid;
use App\Enums\ClaimOutcome;
use App\Enums\OrderStatus;
use App\Enums\ProductKey;
use App\Mail\OrderPaid;
use App\Models\Customer;
use App\Models\Gift;
use App\Models\Order;
use App\Models\Product;
use App\Services\Payments\ManualSettlement;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;

uses(RefreshDatabase::class);

/*
| The claim link on the confirmation page, issue #122, and its retirement by
| #128 on 14 November 2026.
|
| ~~`POST /orders/status` hands the page the receipt's own set-password link,
| for a first-time Viewing Room pass buyer, for fifteen minutes after
| settlement.~~ Retired 14 November 2026, #128's second step, decided in #126:
| the page signs that buyer in through `POST /orders/enter` instead, and
| `/orders/status` never carries a link again. **The two keys stay**, answering
| `"none"` and `null` on every 200, so a site that still reads them is not
| broken; removing them is a follow-up once the frontend has stopped reading
| them. The receipt still carries the link, by the same mint as before.
|
| Everything here runs against the real `customers` broker rather than a mock,
| because whether the receipt's link survives is the broker's behaviour.
|
| **Nothing in this file proves Stripe**, for the reason `ReadPaymentStatusTest`
| gives. The one test that needs a Stripe answer substitutes it.
*/

beforeEach(function () {
    Mail::fake();

    config()->set('frontend.url', 'https://theworldtarot.com');

    // `/pay` is reached in three tests below, and none is about a gateway.
    config()->set('payments.methods', ['manual' => ManualSettlement::class]);
    config()->set('payments.default', 'manual');
});

/**
 * A pass order whose buyer has no password and no other order, which is the
 * one shape of order the page was ever handed a link for, and so the shape the
 * retirement has to be proved against.
 */
function aFirstPassOrder(?Customer $buyer = null): Order
{
    return Order::factory()
        ->for($buyer ?? Customer::factory()->create(['password' => null]))
        ->forProduct(Product::factory()->pass()->create())
        ->create();
}

function settleForThePage(Order $order): void
{
    expect(app(MarkOrderPaid::class)($order))->toBeTrue();
}

function askThePage(Order $order): TestResponse
{
    return test()->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token]);
}

/**
 * The set-password link the newest receipt carries, read off the mailable's own
 * view data so the comparison is with what the buyer was actually sent.
 */
function setPasswordLinkInTheReceipt(): ?string
{
    $mail = Mail::queued(OrderPaid::class)->last();

    return $mail?->content()->with['setPasswordUrl'] ?? null;
}

function tokenIn(string $url): string
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query['token'];
}

/**
 * The whole 200 the page gets since #128's retire step, whatever the order.
 *
 * @return array{status: string, set_password_link: string, set_password_url: null}
 */
function noLinkForThePage(string $status = 'succeeded'): array
{
    return ['status' => $status, 'set_password_link' => 'none', 'set_password_url' => null];
}

/*
| ~~The one case that gets a link.~~ Retired 14 November 2026, #128: the case
| that used to get one gets none, and the receipt still carries it.
*/

it('hands a first-time pass buyer no link once the order is settled, while the receipt still carries one', function () {
    $order = aFirstPassOrder();
    settleForThePage($order);

    expect(setPasswordLinkInTheReceipt())->toBeString();

    askThePage($order)->assertOk()->assertExactJson(noLinkForThePage());
});

it('hands no link for an order holding the pass beside a reading', function () {
    $order = Order::factory()
        ->for(Customer::factory()->create(['password' => null]))
        ->forProduct(Product::factory()->pass()->create())
        ->forProduct(Product::factory()->create(['key' => ProductKey::MonthAhead]))
        ->create();

    settleForThePage($order);

    askThePage($order)->assertOk()->assertExactJson(noLinkForThePage());
});

/**
 * **Settlement keeps nothing for the page any more.** `ClaimLinkHandover::keep()`
 * put the receipt's token, encrypted, under `claim-link-for-the-page:{id}` for
 * fifteen minutes. Listening for every cache write rather than asking for the
 * one key, so a copy kept under any id is caught too.
 */
it('settles a first-time pass order without keeping its claim token in the cache', function () {
    $order = aFirstPassOrder();

    $written = [];

    Event::listen(KeyWritten::class, function (KeyWritten $event) use (&$written): void {
        $written[] = $event->key;
    });

    settleForThePage($order);

    expect(setPasswordLinkInTheReceipt())->toBeString()
        ->and(array_filter($written, fn (string $key): bool => str_starts_with($key, 'claim-link-for-the-page:')))->toBe([])
        ->and(Cache::has('claim-link-for-the-page:'.$order->getKey()))->toBeFalse();
});

/**
 * Asking the page mints nothing and spends nothing, so the receipt's link is
 * the buyer's one live link whether or not the page has been asked. This held
 * under #122 as well, where the page was handed that same token.
 */
it('leaves the receipt\'s link working after the page has been asked', function () {
    $buyer = Customer::factory()->create(['password' => null]);
    $order = aFirstPassOrder($buyer);
    settleForThePage($order);

    askThePage($order)->assertOk();
    askThePage($order)->assertOk();

    $token = tokenIn(setPasswordLinkInTheReceipt());

    expect(Password::broker('customers')->tokenExists($buyer, $token))->toBeTrue()
        ->and(app(ClaimAccount::class)($buyer->email, $token, 'a-first-password-123'))
        ->toBe(ClaimOutcome::Claimed);
});

/*
| Every case that always got none. The URL is null, never an empty string.
*/

it('hands no link to a buyer who already has a password', function () {
    $order = aFirstPassOrder(Customer::factory()->create());
    settleForThePage($order);

    askThePage($order)->assertOk()->assertExactJson([
        'status' => 'succeeded',
        'set_password_link' => 'none',
        'set_password_url' => null,
    ]);
});

it('hands no link for a reading order, even to an account with no password', function () {
    $order = Order::factory()
        ->for(Customer::factory()->create(['password' => null]))
        ->forProduct(Product::factory()->create(['key' => ProductKey::MonthAhead]))
        ->create();

    settleForThePage($order);

    askThePage($order)->assertOk()->assertExactJson([
        'status' => 'succeeded',
        'set_password_link' => 'none',
        'set_password_url' => null,
    ]);
});

it('hands no link for a gift order', function () {
    $order = Order::factory()
        ->for(Customer::factory()->create(['password' => null]))
        ->forProduct(Product::factory()->create(['key' => ProductKey::MonthAhead]))
        ->create();

    Gift::factory()->unminted()->create(['order_line_id' => $order->lines->sole()->id]);

    settleForThePage($order);

    askThePage($order)->assertOk()->assertExactJson([
        'status' => 'succeeded',
        'set_password_link' => 'none',
        'set_password_url' => null,
    ]);
});

/**
 * ~~"Not yet" for an unpaid pass order, so the page asked again.~~ Retired 14
 * November 2026, #128: there is nothing to wait for, so the page is told to
 * stop asking at once. `not_yet` is never answered any more.
 */
it('says an unpaid pass order will never have a link', function () {
    $order = aFirstPassOrder();

    askThePage($order)->assertOk()->assertExactJson(noLinkForThePage('requires_payment_method'));
});

it('says an unpaid reading order will never have a link', function () {
    $order = Order::factory()
        ->for(Customer::factory()->create(['password' => null]))
        ->forProduct(Product::factory()->create(['key' => ProductKey::MonthAhead]))
        ->create();

    askThePage($order)->assertOk()->assertExactJson([
        'status' => 'requires_payment_method',
        'set_password_link' => 'none',
        'set_password_url' => null,
    ]);
});

/**
 * **The account-claim guard**, which lives on as bound 3 of `/orders/enter`
 * (`ClaimLinkHandover::isFor()`). Stripe's hosted page lets whoever pays type
 * any address, and settlement attaches the order to the account that address
 * already has.
 */
it('hands no link when the account had an order before this one', function (OrderStatus $earlier) {
    $buyer = Customer::factory()->create(['password' => null]);

    Order::factory()
        ->for($buyer)
        ->forProduct(Product::factory()->create(['key' => ProductKey::OneCard]))
        ->state(['status' => $earlier, 'paid_at' => $earlier === OrderStatus::Paid ? now()->subMonth() : null])
        ->create();

    $order = aFirstPassOrder($buyer);
    settleForThePage($order);

    // The receipt still carries the link, to the address that owns the account.
    expect(setPasswordLinkInTheReceipt())->toBeString();

    askThePage($order)->assertOk()
        ->assertJsonPath('set_password_link', 'none')
        ->assertJsonPath('set_password_url', null);
})->with([
    'a paid one' => OrderStatus::Paid,
    'an abandoned one' => OrderStatus::Pending,
]);

it('does not know an unknown token, answering exactly as it did before', function () {
    $order = aFirstPassOrder();
    settleForThePage($order);

    // The body under test carries debug output that no deployed environment
    // has, so what is pinned is the status and that no link key rides on it.
    $this->postJson('/api/v1/orders/status', ['pay_token' => Order::mintPayToken()])
        ->assertNotFound()
        ->assertJsonMissingPath('set_password_link')
        ->assertJsonMissingPath('set_password_url');
});

it('refuses a signed in customer somebody else\'s order', function () {
    $order = aFirstPassOrder();
    settleForThePage($order);

    $this->actingAs(Customer::factory()->create(), 'sanctum')
        ->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertNotFound();
});

/**
 * ~~Now that the answer can carry a credential.~~ It carries none since 14
 * November 2026, #128, and it is still a held answer about somebody's money,
 * which was the reason before #122.
 */
it('still refuses to be cached', function () {
    $order = aFirstPassOrder();
    settleForThePage($order);

    askThePage($order)->assertOk()
        ->assertJsonPath('set_password_link', 'none')
        ->assertHeader('Cache-Control', 'no-store, private');
});

/*
| Timing. The card road paints `received` before the webhook settles. ~~So the
| first answer is "not yet" and the link follows settlement.~~ Since #128 the
| answer is "none" on both sides of the webhook.
*/

it('says none while Stripe reports success and the webhook has not settled, and none after it has', function () {
    $order = aFirstPassOrder();
    $order->forceFill(['customer_id' => null, 'stripe_payment_intent_id' => 'pi_before_the_webhook'])->save();

    $intents = Mockery::mock(PaymentIntentService::class);
    $intents->shouldReceive('retrieve')->andReturn(PaymentIntent::constructFrom([
        'id' => 'pi_before_the_webhook',
        'object' => 'payment_intent',
        'status' => 'succeeded',
    ]));
    substitutedStripe($intents);

    askThePage($order)->assertOk()->assertExactJson(noLinkForThePage());

    // The webhook learns who paid and settles, as `RecordStripePayment` does.
    $order->forceFill(['customer_id' => Customer::factory()->create(['password' => null])->id])->save();
    settleForThePage($order->refresh());

    expect(setPasswordLinkInTheReceipt())->toBeString();

    askThePage($order)->assertOk()->assertExactJson(noLinkForThePage());
});

/*
| Where the pay token travels. ~~A token that travelled in a path is never
| handed a link, and one sent in a body is.~~ Since #128 neither is; the path
| rule lives on as bound 5 of `/orders/enter`.
*/

it('hands no link for an order whose pay token was sent in a path', function (bool $beforeSettlement) {
    $order = aFirstPassOrder();

    if ($beforeSettlement) {
        $this->postJson("/api/v1/orders/{$order->pay_token}/pay")->assertOk();
    }

    settleForThePage($order);

    if (! $beforeSettlement) {
        $this->postJson("/api/v1/orders/{$order->pay_token}/pay")->assertOk();
    }

    askThePage($order)->assertOk()
        ->assertJsonPath('set_password_link', 'none')
        ->assertJsonPath('set_password_url', null);
})->with([
    'before settlement' => true,
    'after settlement' => false,
]);

it('hands no link either when the payment was started with the token in the body', function () {
    $order = aFirstPassOrder();

    $this->postJson('/api/v1/orders/pay', ['pay_token' => $order->pay_token])->assertOk();

    settleForThePage($order);

    askThePage($order)->assertOk()->assertExactJson(noLinkForThePage());
});

it('writes nothing to the log while answering a first-time pass buyer', function () {
    $order = aFirstPassOrder();
    settleForThePage($order);

    // **Every line, at any level**, which cannot be passed by a leak arriving
    // through a method nobody thought to watch.
    $written = [];

    Log::listen(function (MessageLogged $entry) use (&$written): void {
        $written[] = $entry->message.json_encode($entry->context);
    });

    $url = askThePage($order)->assertOk()->json('set_password_url');

    expect($url)->toBeNull()
        ->and($written)->toBe([]);
});
