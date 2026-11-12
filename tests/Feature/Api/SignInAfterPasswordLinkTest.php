<?php

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

/*
| Following a password link signs the customer in. Issue #124.
|
| A successful `POST /set-password` or `POST /reset-password` ends the way
| signing in ends, through `IssuesCredentials`: the cookie session, a fresh
| session id, and the customer body login answers. Holding a live token is
| proof of control of the mailbox, which is the trust the claim path already
| relied on when the website signed in for itself with a second request.
|
| **Every case here is about the cookie, so every case handles the cookie.**
| The harness does not carry cookies from one request to the next, and it keeps
| one session store for the whole test, so "the next request from this
| browser" has to be said out loud. `asBrowserHolding()` is that sentence.
*/

beforeEach(function () {
    // Sanctum's stateful middleware only applies to an origin it recognises,
    // and without it there is no session at all. See MemberAuthTest.
    $this->withHeader('Origin', 'http://localhost');
});

dataset('password links', [
    // An account a purchase made, which has never had a password.
    'a claim link' => ['/api/v1/set-password', null],
    // An account that already has one, which is where a repeat pass receipt
    // sends somebody since #114.
    'a reset link' => ['/api/v1/reset-password', 'the-old-password'],
]);

function linkHolder(?string $password, string $email = 'jennifer@example.com'): Customer
{
    return Customer::factory()->create(['email' => $email, 'password' => $password]);
}

function followLink(string $endpoint, Customer $customer, ?string $token = null, array $extra = []): TestResponse
{
    return test()->postJson($endpoint, array_merge([
        'token' => $token ?? passwordTokenFor($customer),
        'email' => $customer->email,
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ], $extra));
}

/** The session id a response handed the browser, decrypted. */
function sessionIdFrom(TestResponse $response): ?string
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
 *   replacing them, so a signed in key from an earlier request would answer
 *   for a session id that never held it
 *
 * An empty string is a browser holding no session: an id that is not valid is
 * replaced with a fresh one, exactly as a first visit is.
 */
function asBrowserHolding(?string $sessionId): void
{
    Auth::forgetGuards();
    app('session.store')->flush();

    test()->withCredentials()->withCookie(config('session.cookie'), $sessionId ?? '');
}

it('signs the customer in when the link is followed', function (string $endpoint, ?string $old) {
    $customer = linkHolder($old);

    $response = followLink($endpoint, $customer)->assertOk();

    $this->assertAuthenticatedAs($customer, 'web');

    // And on the wire, which is the part the website relies on: the cookie the
    // answer set is a signed in session.
    asBrowserHolding(sessionIdFrom($response));

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('email', 'jennifer@example.com');
})->with('password links');

it('answers the same customer body signing in answers', function (string $endpoint, ?string $old) {
    $customer = linkHolder($old);

    $followed = followLink($endpoint, $customer)->assertOk();

    asBrowserHolding('');

    $login = $this->postJson('/api/v1/login', [
        'email' => 'jennifer@example.com',
        'password' => 'a-brand-new-password',
    ])->assertOk();

    // Exactly login's body, key for key, plus the message these two endpoints
    // have always answered. Keeping it is what makes this additive for a client
    // that reads it, and nothing else may be added or missing.
    expect(Arr::except($followed->json(), 'message'))->toBe($login->json())
        ->and($followed->json('message'))->toBeString()
        ->and($followed->json())->not->toHaveKey('token')
        ->and($followed->headers->get('Cache-Control'))->toContain('no-store');
})->with('password links');

it('regenerates the session id rather than signing in the one the browser held', function (string $endpoint, ?string $old) {
    $customer = linkHolder($old);

    // What the website does before every write.
    $before = sessionIdFrom($this->get('/sanctum/csrf-cookie'));
    expect($before)->not->toBeEmpty();

    asBrowserHolding($before);

    $after = sessionIdFrom(followLink($endpoint, $customer)->assertOk());

    // Session fixation: an id that existed before sign in must never be valid
    // after it, or whoever planted that id is signed in too.
    expect($after)->not->toBeEmpty()->not->toBe($before);

    asBrowserHolding($before);
    $this->getJson('/api/v1/me')->assertUnauthorized();

    asBrowserHolding($after);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'jennifer@example.com');
})->with('password links');

it('replaces a different customer already signed in in that browser', function (string $endpoint, ?string $old) {
    linkHolder('their-own-password', 'someone@example.com');

    $theirs = sessionIdFrom($this->postJson('/api/v1/login', [
        'email' => 'someone@example.com',
        'password' => 'their-own-password',
    ])->assertOk());

    $customer = linkHolder($old);

    asBrowserHolding($theirs);

    // **Holding a live token is proof of control of that mailbox**, and the
    // person following it means to be that customer. Leaving the other one
    // signed in would land them in somebody else's account at `next`.
    $followed = followLink($endpoint, $customer)->assertOk()
        ->assertJsonPath('email', 'jennifer@example.com');

    $this->assertAuthenticatedAs($customer, 'web');

    asBrowserHolding(sessionIdFrom($followed));
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'jennifer@example.com');

    // And the id the other customer's session lived under is worth nothing.
    asBrowserHolding($theirs);
    $this->getJson('/api/v1/me')->assertUnauthorized();
})->with('password links');

/*
| Refusals. These pass on the code before #124, deliberately: they are the
| half of the change that must not move. A refused link is answered exactly as
| it always was, signs nobody in, and leaves whoever was signed in alone.
*/

dataset('refused links', [
    'an invented token' => ['not-a-real-token', []],
    'a password that breaks the rules' => [null, ['password' => 'short', 'password_confirmation' => 'short']],
]);

it('creates no session for a refused link', function (string $endpoint, ?string $old, ?string $token, array $extra) {
    $customer = linkHolder($old);

    $response = followLink($endpoint, $customer, $token, $extra)->assertStatus(422);

    $this->assertGuest('web');

    expect(array_keys($response->json()))->toBe(['message', 'errors']);

    asBrowserHolding(sessionIdFrom($response));
    $this->getJson('/api/v1/me')->assertUnauthorized();
})->with('password links')->with('refused links');

it('creates no session for a claim link on an account that already has a password', function () {
    $customer = linkHolder('the-original-password');

    followLink('/api/v1/set-password', $customer)->assertStatus(422);

    $this->assertGuest('web');
    expect(Hash::check('the-original-password', $customer->fresh()->password))->toBeTrue();
});

it('leaves whoever was signed in alone when the link is refused', function (string $endpoint, ?string $old) {
    linkHolder('their-own-password', 'someone@example.com');

    $theirs = sessionIdFrom($this->postJson('/api/v1/login', [
        'email' => 'someone@example.com',
        'password' => 'their-own-password',
    ])->assertOk());

    $customer = linkHolder($old);

    asBrowserHolding($theirs);
    followLink($endpoint, $customer, 'not-a-real-token')->assertStatus(422);

    asBrowserHolding($theirs);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'someone@example.com');
})->with('password links');

/*
| The edges of "signs in".
*/

it('still sets the password and answers the message when the request cannot hold a session', function (string $endpoint, ?string $old) {
    $customer = linkHolder($old);

    // No recognised origin, so Sanctum's stateful middleware does not apply and
    // there is no session to sign in. Login answers this with a 422 on
    // `device_name`; here the password is already saved by then, so a 422
    // would tell somebody their password was not set when it was.
    $this->withoutHeader('Origin');

    $response = followLink($endpoint, $customer)->assertOk();

    expect(array_keys($response->json()))->toBe(['message'])
        ->and(Hash::check('a-brand-new-password', $customer->fresh()->password))->toBeTrue();
})->with('password links');

it('never hands out a bearer token, even when a device is named', function (string $endpoint, ?string $old) {
    $customer = linkHolder($old);

    // A token is a credential that outlives the link by months. Native apps
    // sign in with `/login` after the password is set, as they always have.
    followLink($endpoint, $customer, null, ['device_name' => 'phone'])
        ->assertOk()
        ->assertJsonMissingPath('token');

    expect($customer->tokens()->count())->toBe(0);
})->with('password links');

/**
 * What a reset already does to the customer's other browser sessions.
 *
 * Pinned because the contract and `docs/CHECKPOINT.md` said for three months
 * that it did nothing, and that was not true. Sanctum's `AuthenticateSession`
 * is in the stateful pipeline by default (`config/sanctum.php`,
 * `authenticate_session`). It stores a hash of the password in the session on
 * every authenticated request, and signs the session out on the first request
 * whose stored hash no longer matches. So a reset ends every other session that
 * has made one authenticated request since it signed in, on its next request.
 *
 * The website asks `/me` on every page load, so in practice that is every
 * session the website holds. A session that signed in and has made no request
 * since carries no hash yet and survives; that is the gap left, and it is
 * stated in the contract rather than closed here.
 */
it('ends the customer\'s other browser sessions that have been used since they signed in', function () {
    $customer = linkHolder('the-old-password');

    $elsewhere = sessionIdFrom($this->postJson('/api/v1/login', [
        'email' => 'jennifer@example.com',
        'password' => 'the-old-password',
    ])->assertOk());

    // The page load after signing in, which is what stores the hash.
    asBrowserHolding($elsewhere);
    $this->getJson('/api/v1/me')->assertOk();

    // Somebody else, in another browser, holding the reset link.
    asBrowserHolding('');
    followLink('/api/v1/reset-password', $customer)->assertOk();

    asBrowserHolding($elsewhere);
    $this->getJson('/api/v1/me')->assertUnauthorized();
});
