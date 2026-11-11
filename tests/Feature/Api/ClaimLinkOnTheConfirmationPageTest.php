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
use App\Support\FrontendUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Stripe\PaymentIntent;
use Stripe\Service\PaymentIntentService;

uses(RefreshDatabase::class);

/*
| The claim link on the confirmation page, issue #122.
|
| `POST /orders/status` hands the page the receipt's own set-password link, for
| a first-time Viewing Room pass buyer, for fifteen minutes after settlement.
| Everything here runs against the real `customers` broker rather than a mock,
| because which link survives is the broker's behaviour and nothing else's.
|
| **Nothing in this file proves Stripe**, for the reason `ReadPaymentStatusTest`
| gives. The one test that needs a Stripe answer substitutes it.
*/

beforeEach(function () {
    Mail::fake();

    config()->set('frontend.url', 'https://theworldtarot.com');

    // `/pay` is reached in two tests below, and neither is about a gateway.
    config()->set('payments.methods', ['manual' => ManualSettlement::class]);
    config()->set('payments.default', 'manual');
});

/**
 * A pass order whose buyer has no password and no other order, which is the
 * one shape of order the page is handed a link for.
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

/*
| The one case that gets a link.
*/

it('hands a first-time pass buyer the receipt\'s own set-password link once the order is settled', function () {
    $order = aFirstPassOrder();
    settleForThePage($order);

    $receipt = setPasswordLinkInTheReceipt();
    expect($receipt)->toBeString();

    $answer = askThePage($order)->assertOk();

    expect($answer->json('status'))->toBe('succeeded')
        ->and($answer->json('set_password_link'))->toBe('ready')
        ->and($answer->json('set_password_url'))->toBe($receipt);
});

it('builds the link with FrontendUrl::setPassword for the pass, next included', function () {
    $buyer = Customer::factory()->create(['password' => null, 'email' => 'first.time@example.com']);
    $order = aFirstPassOrder($buyer);
    settleForThePage($order);

    $url = askThePage($order)->json('set_password_url');

    expect($url)->toBe(FrontendUrl::setPassword('first.time@example.com', tokenIn($url), ProductKey::ViewingRoomPass))
        ->and($url)->toStartWith('https://theworldtarot.com/set-password/?token=')
        ->and($url)->toContain('&email=first.time%40example.com')
        ->and($url)->toEndWith('&next=%2Fviewing-room%2F');
});

it('hands over a link that the set-password endpoint accepts', function () {
    $buyer = Customer::factory()->create(['password' => null]);
    $order = aFirstPassOrder($buyer);
    settleForThePage($order);

    $url = askThePage($order)->json('set_password_url');

    expect(app(ClaimAccount::class)($buyer->email, tokenIn($url), 'a-first-password-123'))
        ->toBe(ClaimOutcome::Claimed);
});

it('hands the link over for an order holding the pass beside a reading', function () {
    $order = Order::factory()
        ->for(Customer::factory()->create(['password' => null]))
        ->forProduct(Product::factory()->pass()->create())
        ->forProduct(Product::factory()->create(['key' => ProductKey::MonthAhead]))
        ->create();

    settleForThePage($order);

    askThePage($order)->assertOk()->assertJsonPath('set_password_link', 'ready');
});

/*
| Every case that gets none. The URL is null, never an empty string, and the
| state says whether asking again could change that.
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

it('hands no link for an unpaid pass order, and says one may still come', function () {
    $order = aFirstPassOrder();

    askThePage($order)->assertOk()->assertExactJson([
        'status' => 'requires_payment_method',
        'set_password_link' => 'not_yet',
        'set_password_url' => null,
    ]);
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
 * **The account-claim guard.** Stripe's hosted page lets whoever pays type any
 * address, and settlement attaches the order to the account that address
 * already has. An account with history on it was not made by the person
 * holding this pay token, so the page never hands them its first password.
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

it('refuses a signed in customer somebody else\'s order, link or no link', function () {
    $order = aFirstPassOrder();
    settleForThePage($order);

    $this->actingAs(Customer::factory()->create(), 'sanctum')
        ->postJson('/api/v1/orders/status', ['pay_token' => $order->pay_token])
        ->assertNotFound();
});

it('still refuses to be cached now that the answer can carry a credential', function () {
    $order = aFirstPassOrder();
    settleForThePage($order);

    askThePage($order)->assertOk()
        ->assertJsonPath('set_password_link', 'ready')
        ->assertHeader('Cache-Control', 'no-store, private');
});

/*
| Timing. The card road paints `received` before the webhook settles, so the
| first answer is "not yet" and the link follows settlement.
*/

it('says not yet while Stripe reports success and the webhook has not settled, then hands the link over', function () {
    $order = aFirstPassOrder();
    $order->forceFill(['customer_id' => null, 'stripe_payment_intent_id' => 'pi_before_the_webhook'])->save();

    $intents = Mockery::mock(PaymentIntentService::class);
    $intents->shouldReceive('retrieve')->andReturn(PaymentIntent::constructFrom([
        'id' => 'pi_before_the_webhook',
        'object' => 'payment_intent',
        'status' => 'succeeded',
    ]));
    substitutedStripe($intents);

    askThePage($order)->assertOk()->assertExactJson([
        'status' => 'succeeded',
        'set_password_link' => 'not_yet',
        'set_password_url' => null,
    ]);

    // The webhook learns who paid and settles, as `RecordStripePayment` does.
    $order->forceFill(['customer_id' => Customer::factory()->create(['password' => null])->id])->save();
    settleForThePage($order->refresh());

    askThePage($order)->assertOk()
        ->assertJsonPath('status', 'succeeded')
        ->assertJsonPath('set_password_link', 'ready')
        ->assertJsonPath('set_password_url', setPasswordLinkInTheReceipt());
});

it('stops handing the link over fifteen minutes after settlement', function () {
    $order = aFirstPassOrder();
    settleForThePage($order);

    $this->travel(14)->minutes();
    askThePage($order)->assertJsonPath('set_password_link', 'ready');

    $this->travel(2)->minutes();
    askThePage($order)->assertOk()->assertExactJson([
        'status' => 'succeeded',
        'set_password_link' => 'none',
        'set_password_url' => null,
    ]);
});

/*
| One token, the receipt's. The page and the inbox never hold two links that
| kill each other, and the endpoint never hands over a link that is dead.
*/

it('hands over the very token the receipt carries, so neither link kills the other', function () {
    $buyer = Customer::factory()->create(['password' => null]);
    $order = aFirstPassOrder($buyer);
    settleForThePage($order);

    $receiptToken = tokenIn(setPasswordLinkInTheReceipt());
    $pageToken = tokenIn(askThePage($order)->json('set_password_url'));

    expect($pageToken)->toBe($receiptToken)
        ->and(Password::broker('customers')->tokenExists($buyer, $receiptToken))->toBeTrue();
});

it('stops handing the link over once it has been used, from the page or the receipt', function () {
    $buyer = Customer::factory()->create(['password' => null]);
    $order = aFirstPassOrder($buyer);
    settleForThePage($order);

    $token = tokenIn(askThePage($order)->json('set_password_url'));

    expect(app(ClaimAccount::class)($buyer->email, $token, 'a-first-password-123'))->toBe(ClaimOutcome::Claimed);

    askThePage($order)->assertOk()
        ->assertJsonPath('set_password_link', 'none')
        ->assertJsonPath('set_password_url', null);
});

/**
 * **A later mint for the same address kills this token**, because the broker
 * keeps one per address. A reset asked for from the sign in page after its
 * sixty second throttle is the ordinary way that happens. The page must then
 * stop offering the dead link, and the newest one, in the newest mail, works:
 * the buyer is never left holding two dead links.
 */
it('stops handing over a link a newer one has replaced, and the newer one works', function () {
    $buyer = Customer::factory()->create(['password' => null]);
    $order = aFirstPassOrder($buyer);
    settleForThePage($order);

    $first = tokenIn(askThePage($order)->json('set_password_url'));

    $this->travel(2)->minutes();
    $newer = Password::broker('customers')->createToken($buyer);

    expect(Password::broker('customers')->tokenExists($buyer, $first))->toBeFalse();

    askThePage($order)->assertOk()
        ->assertJsonPath('set_password_link', 'none')
        ->assertJsonPath('set_password_url', null);

    expect(app(ClaimAccount::class)($buyer->email, $newer, 'a-first-password-123'))->toBe(ClaimOutcome::Claimed);
});

/*
| Where the pay token travels.
*/

/**
 * **A pay token that has been in a path is in somebody's access log**, so the
 * order it belongs to is never handed a link on the page. The legacy
 * `/orders/{pay_token}/pay` route is the only place one travels in a path, and
 * it stays until the website has moved to the body route.
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

it('still hands the link over when the payment was started with the token in the body', function () {
    $order = aFirstPassOrder();

    $this->postJson('/api/v1/orders/pay', ['pay_token' => $order->pay_token])->assertOk();

    settleForThePage($order);

    askThePage($order)->assertOk()->assertJsonPath('set_password_link', 'ready');
});

it('writes nothing to the log while handing the link over', function () {
    $order = aFirstPassOrder();
    settleForThePage($order);

    // **Every line, at any level.** The new path writes none at all, which is
    // stronger than looking for the two credentials it handles and cannot be
    // passed by a leak arriving through a method nobody thought to watch.
    $written = [];

    Log::listen(function (MessageLogged $entry) use (&$written): void {
        $written[] = $entry->message.json_encode($entry->context);
    });

    $url = askThePage($order)->assertOk()->json('set_password_url');

    expect($url)->toBeString()
        ->and($written)->toBe([]);
});
