<?php

use App\Actions\MarkOrderPaid;
use App\Enums\OrderStatus;
use App\Enums\ProductKey;
use App\Mail\OrderPaid;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Services\Payments\ManualSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

uses(RefreshDatabase::class);

/*
| Paying signs a first-time pass buyer in. Issue #128, decided in #126 on
| 14 November 2026.
|
| `POST /orders/enter` starts a cookie session for the browser that paid, once,
| and only for an account this payment made. Every case below is about that
| session, so every case says which browser is asking: the harness carries no
| cookie from one request to the next and keeps one session store for the whole
| test, and `aBrowserHolding()` is what makes "the next request from this
| browser" mean what it means in a browser.
|
| **The gate is `ClaimLinkHandover`'s bounds, reused.** Every denial below is a
| bound that class already states for #122, asked again here by the same code.
*/

beforeEach(function () {
    Mail::fake();

    // Sanctum's stateful middleware only applies to an origin it recognises,
    // and without it there is no session at all. See MemberAuthTest.
    $this->withHeader('Origin', 'http://localhost');

    // The legacy `/pay` path route is reached in the path test, and that test
    // is not about a gateway.
    config()->set('payments.methods', ['manual' => ManualSettlement::class]);
    config()->set('payments.default', 'manual');
});

/**
 * A pass order whose buyer has no password and no other order: the one shape of
 * order that may sign anybody in.
 */
function aPassOrderToEnter(?Customer $buyer = null): Order
{
    return Order::factory()
        ->for($buyer ?? Customer::factory()->create(['password' => null, 'email' => 'first.time@example.com']))
        // One pass row however many orders a test makes, since `key` is unique.
        ->forProduct(Product::where('key', ProductKey::ViewingRoomPass)->first() ?? Product::factory()->pass()->create())
        ->create();
}

function settleTheEntry(Order $order): void
{
    expect(app(MarkOrderPaid::class)($order))->toBeTrue();
}

function enterWith(Order|string $order, array $extra = []): TestResponse
{
    return test()->postJson('/api/v1/orders/enter', [
        'pay_token' => $order instanceof Order ? $order->pay_token : $order,
        ...$extra,
    ]);
}

/** The session id a response handed the browser, decrypted, or null. */
function sessionCookieFrom(TestResponse $response): ?string
{
    return $response->getCookie(config('session.cookie'))?->getValue();
}

/**
 * Make the next request the one a browser holding `$sessionId` would make.
 *
 * Three things the harness does that a browser does not, undone here:
 *
 * - **It sends no cookies on a JSON request** unless told to
 * - **It keeps the guard's resolved user** from the request before
 * - **It keeps the session store's attributes** from the request before, and
 *   `Store::start()` merges what the handler holds into them rather than
 *   replacing them, so a signed in key from one browser would answer for
 *   another
 *
 * An empty string is a browser holding no session yet: an id that is not valid
 * is replaced with a fresh one, exactly as on a first visit.
 *
 * The same three moves as `asBrowserHolding()` in #124's test file (PR #125, not
 * merged when this was written). Named apart so the two files can both load.
 */
function aBrowserHolding(string $sessionId): void
{
    Auth::forgetGuards();
    app('session.store')->flush();

    test()->withCredentials()->withCookie(config('session.cookie'), $sessionId);
}

/** The set-password token the newest receipt carries. */
function tokenInTheEntryReceipt(): string
{
    $url = Mail::queued(OrderPaid::class)->last()?->content()->with['setPasswordUrl'] ?? '';
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query['token'];
}

/*
| The one case that signs somebody in.
*/

it('signs a first-time pass buyer in, on a fresh session id, with the body /login answers', function () {
    $order = aPassOrderToEnter();
    settleTheEntry($order);

    // The browser already holds a session before it pays, as the website's
    // does: it asked for its CSRF cookie on the way to checkout.
    $before = sessionCookieFrom($this->get('/sanctum/csrf-cookie'));
    expect($before)->toBeString()->not->toBe('');

    aBrowserHolding($before);
    $entered = enterWith($order)->assertOk();

    expect($entered->json())->toBe([
        'id' => $order->customer->id,
        'name' => $order->customer->name,
        'email' => 'first.time@example.com',
        'created_at' => $order->customer->created_at->toIso8601String(),
        'has_viewing_room_access' => true,
    ]);

    $after = sessionCookieFrom($entered);
    expect($after)->toBeString()->not->toBe($before);

    // The new id is signed in as the buyer, which is what the website reads.
    aBrowserHolding($after);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'first.time@example.com');

    // Session fixation: the id that existed before is worth nothing.
    aBrowserHolding($before);
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('answers 409 before settlement and 200 once settled, the 409 having spent nothing', function () {
    $order = aPassOrderToEnter();

    enterWith($order)->assertStatus(409)->assertJsonStructure(['message']);
    enterWith($order)->assertStatus(409);

    settleTheEntry($order->refresh());

    aBrowserHolding('');
    enterWith($order)->assertOk()->assertJsonPath('email', 'first.time@example.com');
});

it('answers 409 for a hosted checkout whose webhook has not yet said who paid', function () {
    $order = aPassOrderToEnter();
    $order->forceFill(['customer_id' => null])->save();

    enterWith($order)->assertStatus(409)->assertJsonStructure(['message']);
});

/*
| Every 403. Each is a token that will never sign anybody in, so the page shows
| "sign in to enter".
*/

it('refuses an account that already has a password', function () {
    $order = aPassOrderToEnter(Customer::factory()->create());
    settleTheEntry($order);

    enterWith($order)->assertForbidden()->assertJsonStructure(['message']);
    $this->assertGuest('web');
});

it('refuses an unclaimed account that holds another order', function (OrderStatus $earlier) {
    $buyer = Customer::factory()->create(['password' => null]);

    Order::factory()
        ->for($buyer)
        ->forProduct(Product::factory()->create(['key' => ProductKey::OneCard]))
        ->state(['status' => $earlier, 'paid_at' => $earlier === OrderStatus::Paid ? now()->subMonth() : null])
        ->create();

    $order = aPassOrderToEnter($buyer);
    settleTheEntry($order);

    enterWith($order)->assertForbidden();
    $this->assertGuest('web');
})->with([
    'a paid one' => OrderStatus::Paid,
    'an abandoned one' => OrderStatus::Pending,
]);

it('signs in up to fifteen minutes after settlement and refuses at sixteen', function () {
    $early = aPassOrderToEnter();
    $late = aPassOrderToEnter(Customer::factory()->create(['password' => null]));
    settleTheEntry($early);
    settleTheEntry($late);

    $this->travel(14)->minutes();
    enterWith($early)->assertOk();

    $this->travel(2)->minutes();
    aBrowserHolding('');
    enterWith($late)->assertForbidden();
    $this->assertGuest('web');
});

it('signs in once, and a second browser holding the same token is refused', function () {
    $order = aPassOrderToEnter();
    settleTheEntry($order);

    enterWith($order)->assertOk();

    aBrowserHolding('');
    enterWith($order)->assertForbidden()->assertJsonStructure(['message']);
    $this->assertGuest('web');
});

it('refuses an order whose pay token was sent in a path', function (bool $beforeSettlement) {
    $order = aPassOrderToEnter();

    if ($beforeSettlement) {
        $this->postJson("/api/v1/orders/{$order->pay_token}/pay")->assertOk();
    }

    settleTheEntry($order);

    if (! $beforeSettlement) {
        $this->postJson("/api/v1/orders/{$order->pay_token}/pay")->assertOk();
    }

    aBrowserHolding('');
    enterWith($order)->assertForbidden();
    $this->assertGuest('web');
})->with([
    'before settlement' => true,
    'after settlement' => false,
]);

it('refuses an order with no pass line, even for an account it made', function () {
    $order = Order::factory()
        ->for(Customer::factory()->create(['password' => null]))
        ->forProduct(Product::factory()->create(['key' => ProductKey::MonthAhead]))
        ->create();
    settleTheEntry($order);

    enterWith($order)->assertForbidden();
    $this->assertGuest('web');
});

/**
 * **A request that cannot hold a session is refused before the single use is
 * taken**, so somebody arriving from an origin Sanctum does not recognise
 * cannot spend the buyer's one entry on a session that never existed.
 */
it('refuses a request that cannot hold a session, and spends nothing doing so', function () {
    $order = aPassOrderToEnter();
    settleTheEntry($order);

    $this->withoutHeader('Origin');
    enterWith($order)->assertForbidden()->assertJsonStructure(['message']);

    $this->withHeader('Origin', 'http://localhost');
    aBrowserHolding('');
    enterWith($order)->assertOk();
});

/*
| Every 404, which is the answer `/orders/status` gives in the same cases.
*/

it('does not know an unknown token', function () {
    $order = aPassOrderToEnter();
    settleTheEntry($order);

    enterWith(Order::mintPayToken())->assertNotFound();
    $this->assertGuest('web');
});

it('refuses a customer signed in as somebody else, and signs nobody in', function () {
    $order = aPassOrderToEnter();
    settleTheEntry($order);
    $somebodyElse = Customer::factory()->create();

    $this->actingAs($somebodyElse, 'sanctum');
    enterWith($order)->assertNotFound();

    // Still nobody but the other customer, and the entry is still there for the
    // buyer.
    aBrowserHolding('');
    enterWith($order)->assertOk()->assertJsonPath('email', 'first.time@example.com');
});

/*
| Already in.
*/

it('answers 200 to the order\'s own customer already signed in, and spends nothing', function () {
    $order = aPassOrderToEnter();
    settleTheEntry($order);

    $this->actingAs($order->customer, 'sanctum');
    enterWith($order)->assertOk()->assertJsonPath('email', 'first.time@example.com');

    aBrowserHolding('');
    enterWith($order)->assertOk();
});

it('answers 200 again to the browser it signed in, after the single use is spent', function () {
    $order = aPassOrderToEnter();
    settleTheEntry($order);

    $session = sessionCookieFrom(enterWith($order)->assertOk());

    aBrowserHolding($session);
    enterWith($order)->assertOk()->assertJsonPath('email', 'first.time@example.com');
});

/*
| No bearer token.
*/

it('never mints a bearer token, even when a device is named', function () {
    $order = aPassOrderToEnter();
    settleTheEntry($order);

    enterWith($order, ['device_name' => 'phone'])
        ->assertOk()
        ->assertJsonMissingPath('token');

    expect(PersonalAccessToken::count())->toBe(0);
    $this->assertAuthenticatedAs($order->customer, 'web');
});

/*
| Decision 2 of #126: squatting is accepted, because the owner can always take
| the account back.
|
| Somebody who pays with another person's email is signed in to an account in
| that person's name. When the owner claims it, or resets it, Sanctum's
| `AuthenticateSession` (in the stateful pipeline by default,
| `config/sanctum.php`) finds the password hash it stored in the squatter's
| session no longer matches, and signs that session out on its next request.
|
| **What this pins is the hash of a null password.** The session stored
| `hash_hmac()` of an unclaimed account's null password, and nothing exercised
| that before this. A session that has made no request since entering holds no
| hash yet and survives; that gap is accepted in #126 and stated in the contract.
*/

it('signs the entered session out on its next request once the owner takes the account', function (string $endpoint) {
    $order = aPassOrderToEnter();
    settleTheEntry($order);

    $squatter = sessionCookieFrom(enterWith($order)->assertOk());

    // The page load after entering, which is what stores the hash, and a
    // second one to show the session holds on its own.
    aBrowserHolding($squatter);
    $this->getJson('/api/v1/me')->assertOk();
    aBrowserHolding($squatter);
    $this->getJson('/api/v1/me')->assertOk();

    // The owner, in another browser, holding a link from their own inbox: the
    // receipt's claim link, or a reset they asked for.
    $token = $endpoint === '/api/v1/set-password'
        ? tokenInTheEntryReceipt()
        : Password::broker('customers')->createToken($order->customer->fresh());

    aBrowserHolding('');
    $this->postJson($endpoint, [
        'token' => $token,
        'email' => 'first.time@example.com',
        'password' => 'the-owners-own-password',
        'password_confirmation' => 'the-owners-own-password',
    ])->assertOk();

    aBrowserHolding($squatter);
    $this->getJson('/api/v1/me')->assertUnauthorized();
})->with([
    'claimed through /set-password' => '/api/v1/set-password',
    'reset through /reset-password' => '/api/v1/reset-password',
]);

/*
| Every answer refuses to be stored.
*/

it('marks every answer no-store', function (Closure $arrange, int $status) {
    $token = $arrange();

    enterWith($token)->assertStatus($status)->assertHeader('Cache-Control', 'no-store, private');
})->with([
    'signed in' => [function () {
        $order = aPassOrderToEnter();
        settleTheEntry($order);

        return $order->pay_token;
    }, 200],
    'not settled' => [fn () => aPassOrderToEnter()->pay_token, 409],
    'refused' => [function () {
        $order = aPassOrderToEnter(Customer::factory()->create());
        settleTheEntry($order);

        return $order->pay_token;
    }, 403],
    'unknown' => [fn () => Order::mintPayToken(), 404],
    'invalid' => [fn () => '', 422],
]);

it('throttles on its own counter, and marks the 429 no-store too', function () {
    $token = Order::mintPayToken();

    foreach (range(1, 30) as $_) {
        enterWith($token)->assertNotFound();
    }

    enterWith($token)->assertStatus(429)->assertHeader('Cache-Control', 'no-store, private');

    // Its own prefix: the confirmation page's status poll is not spent by it.
    $this->postJson('/api/v1/orders/status', ['pay_token' => $token])->assertNotFound();
});
