<?php

use App\Http\Requests\Api\V1\LoginRequest;
use App\Models\Admin;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A browser always sends an Origin, and Sanctum's stateful middleware only
    // applies to origins it recognises. Without this header the request is not
    // stateful, there is no session at all, and the cookie path cannot be
    // exercised even in principle. Discovering that here is the cheap version
    // of discovering it on staging.
    $this->withHeader('Origin', 'http://localhost');
});

/**
 * Throw away the resolved user between requests.
 *
 * Laravel keeps one application instance for a whole test, and a guard caches
 * whoever it last resolved. Without this, a request made after signing out is
 * still answered from that cache and every logout test passes whatever the code
 * does. **This is a fact about the test harness, not about the application**, so
 * do not go looking for a bug in the controller if these start failing.
 */
function forgetResolvedUser(): void
{
    Auth::forgetGuards();
}

/*
| Member accounts, both ways in.
|
| The cookie path and the token path are tested separately everywhere it
| matters, because .claude/api.md requires every protected route to work under
| both and a test of one says nothing about the other.
|
| What no test here can prove is the cookie flow as a browser performs it:
| the session cookie, the CSRF cookie, `credentials: "include"` and the stateful
| domain list only interact for real on the deployed origin. That proof is a
| browser on staging, and step 2 is not closed without it.
*/

function registrationPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Jennifer Eisenberger',
        'email' => 'jennifer@example.com',
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ], $overrides);
}

it('makes an account and signs them straight in', function () {
    $this->postJson('/api/v1/register', registrationPayload())
        ->assertCreated()
        ->assertJsonPath('email', 'jennifer@example.com')
        ->assertJsonPath('name', 'Jennifer Eisenberger');

    expect(Customer::where('email', 'jennifer@example.com')->exists())->toBeTrue();

    // Without this the guard hands back the very model register just made, and
    // a resource built from a model with `wasRecentlyCreated` answers 201
    // rather than 200. A real second request boots a fresh application and
    // reads a fresh row, so this is the harness being unlike production again.
    forgetResolvedUser();

    // Signed in already. Being made to type the password again immediately
    // after choosing it is a step that exists for the system's convenience.
    $this->getJson('/api/v1/me')->assertOk();
});

it('never returns the password hash or the remember token', function () {
    $response = $this->postJson('/api/v1/register', registrationPayload());

    $response->assertCreated()
        ->assertJsonMissingPath('password')
        ->assertJsonMissingPath('remember_token');
});

it('stores the password hashed, never as given', function () {
    $this->postJson('/api/v1/register', registrationPayload())->assertCreated();

    $customer = Customer::firstWhere('email', 'jennifer@example.com');

    expect($customer->password)->not->toBe('a-long-enough-password')
        ->and(Hash::check('a-long-enough-password', $customer->password))->toBeTrue();
});

it('refuses an email somebody already has', function () {
    Customer::factory()->create(['email' => 'jennifer@example.com']);

    $this->postJson('/api/v1/register', registrationPayload())
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('treats an email as the same address whatever case it arrives in', function () {
    Customer::factory()->create(['email' => 'jennifer@example.com']);

    // Two accounts that look identical in a support conversation and behave
    // differently at sign in is worse than a refusal.
    $this->postJson('/api/v1/register', registrationPayload(['email' => 'Jennifer@Example.com']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('refuses a password that is too short, and one that was not confirmed', function () {
    $this->postJson('/api/v1/register', registrationPayload([
        'password' => 'short', 'password_confirmation' => 'short',
    ]))->assertStatus(422)->assertJsonValidationErrors('password');

    $this->postJson('/api/v1/register', registrationPayload([
        'password_confirmation' => 'something-else-entirely',
    ]))->assertStatus(422)->assertJsonValidationErrors('password');
});

it('hands a native app a token instead of a session', function () {
    $response = $this->postJson('/api/v1/register', registrationPayload([
        'device_name' => 'Jennifer iPhone',
    ]))->assertCreated();

    $token = $response->json('token');

    expect($token)->toBeString()->not->toBeEmpty();

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('email', 'jennifer@example.com');
});

it('does not put a token in the website response', function () {
    // The website holds a cookie. A token in that response would be a second
    // credential nobody asked for, sitting in whatever the browser logs.
    $this->postJson('/api/v1/register', registrationPayload())
        ->assertCreated()
        ->assertJsonMissingPath('token');
});

it('signs in with the right password', function () {
    Customer::factory()->create([
        'email' => 'jennifer@example.com',
        'password' => 'a-long-enough-password',
    ]);

    $this->postJson('/api/v1/login', [
        'email' => 'jennifer@example.com',
        'password' => 'a-long-enough-password',
    ])->assertOk()->assertJsonPath('email', 'jennifer@example.com');

    $this->getJson('/api/v1/me')->assertOk();
});

it('answers a wrong password exactly as it answers an unknown email', function () {
    Customer::factory()->create([
        'email' => 'jennifer@example.com',
        'password' => 'a-long-enough-password',
    ]);

    $wrongPassword = $this->postJson('/api/v1/login', [
        'email' => 'jennifer@example.com',
        'password' => 'not-the-password',
    ]);

    $unknownEmail = $this->postJson('/api/v1/login', [
        'email' => 'nobody@example.com',
        'password' => 'not-the-password',
    ]);

    // Identical status and identical body. Any difference turns this endpoint
    // into a way of asking whether somebody has an account here.
    expect($wrongPassword->status())->toBe($unknownEmail->status())
        ->and($wrongPassword->json())->toBe($unknownEmail->json());

    $wrongPassword->assertStatus(422);
});

it('takes as long to refuse an unknown email as it takes to refuse a wrong password', function () {
    // **The third channel, and the only one a stopwatch reads.** Status and
    // body are asserted identical directly above. They were, while the endpoint
    // still answered the same question by how long it took, because bcrypt was
    // the one expensive thing on the route and a missing account skipped it.
    //
    // Ten rounds rather than the four this suite runs at, so the hash dominates
    // the request instead of hiding inside its overhead. Set before the
    // customers are made, so their stored hashes carry the same cost and the
    // comparison is between two equal amounts of real work.
    config(['hashing.bcrypt.rounds' => 10]);

    $refuse = function (string $email): float {
        $started = hrtime(true);

        $this->postJson('/api/v1/login', [
            'email' => $email,
            'password' => 'not-the-password',
        ])->assertStatus(422);

        return (hrtime(true) - $started) / 1e6;
    };

    // Thrown away. The first request through a route pays for resolving it.
    $refuse('warming-up@example.com');

    // **A fresh account per measurement.** LoginRequest limits per address and
    // email together, so repeating one email would spend its five attempts and
    // time a 429 instead of a hash.
    $median = fn (array $samples): float => collect($samples)->sort()->values()[1];

    $known = $median(collect(range(1, 3))->map(function () use ($refuse): float {
        $customer = Customer::factory()->create(['password' => 'a-long-enough-password']);

        return $refuse($customer->email);
    })->all());

    $unknown = $median(collect(range(1, 3))->map(
        fn (int $n): float => $refuse("nobody-{$n}@example.com")
    )->all());

    // Deliberately loose. What this has to catch is a missing account costing
    // nothing at all, which measured at roughly one fiftieth of a real check
    // before the fix. A band tight enough to police scheduler noise on CI would
    // fail for reasons that have nothing to do with the property being proved.
    //
    // **Bounded on both sides**, because a stopwatch reads a gap whichever way
    // it opens. The one-sided version this replaced stayed green through an
    // unknown address costing twice what a known one costs, which is the same
    // oracle pointing the other way.
    expect($unknown)->toBeGreaterThan($known * 0.5)
        ->and($unknown)->toBeLessThan($known * 2);
});

it('takes as long to refuse an account that has never had a password', function () {
    // The same oracle, one state further in. An unclaimed account short
    // circuited on the null password rather than the missing row, so somebody
    // who bought as a guest was distinguishable from both a stranger and a
    // customer who had chosen a password.
    config(['hashing.bcrypt.rounds' => 10]);

    $refuse = function (string $email): float {
        $started = hrtime(true);

        $this->postJson('/api/v1/login', [
            'email' => $email,
            'password' => 'not-the-password',
        ])->assertStatus(422);

        return (hrtime(true) - $started) / 1e6;
    };

    $refuse('warming-up@example.com');

    $claimed = Customer::factory()->create(['password' => 'a-long-enough-password']);
    $unclaimed = Customer::factory()->create(['password' => null]);

    $unclaimedCost = $refuse($unclaimed->email);
    $claimedCost = $refuse($claimed->email);

    expect($unclaimedCost)->toBeGreaterThan($claimedCost * 0.5)
        ->and($unclaimedCost)->toBeLessThan($claimedCost * 2);
});

/**
 * The equal cost survives a change to `BCRYPT_ROUNDS`.
 *
 * **The claim was only ever true while the configured cost never moved.**
 * `Hash::make` on the refusal path runs at config rounds; `Hash::check` runs at
 * whatever cost is baked into the stored hash. Production runs twelve against a
 * measured ten, so raising it reopened the gap for every hash written before
 * the change, and the two tests above could not catch it because both set the
 * rounds before creating their customers.
 *
 * `SessionController` now rehashes on a successful sign in, which is what
 * closes it: the customer's stored cost catches up the first time they come
 * back, and the two paths agree again.
 */
it('still costs the same on both paths after the rounds are raised', function () {
    config(['hashing.bcrypt.rounds' => 10]);

    $customer = Customer::factory()->create(['password' => 'a-long-enough-password']);

    expect(password_get_info($customer->password)['options']['cost'])->toBe(10);

    // **A resolved hasher holds the cost it was built with**, so the config
    // change alone would not reach it. This is a fact about the harness rather
    // than about the application, which meets a fresh container on every
    // deploy. Same shape as `forgetResolvedUser` above.
    config(['hashing.bcrypt.rounds' => 12]);
    Hash::forgetDrivers();

    $this->postJson('/api/v1/login', [
        'email' => $customer->email,
        'password' => 'a-long-enough-password',
    ])->assertOk();

    expect(password_get_info($customer->fresh()->password)['options']['cost'])->toBe(12);

    forgetResolvedUser();

    $refuse = function (string $email): float {
        $started = hrtime(true);

        $this->postJson('/api/v1/login', [
            'email' => $email,
            'password' => 'not-the-password',
        ])->assertStatus(422);

        return (hrtime(true) - $started) / 1e6;
    };

    $refuse('warming-up@example.com');

    $known = $refuse($customer->email);
    $unknown = $refuse('nobody-at-all@example.com');

    // The whole point of the rehash. Without it the known address is checked at
    // ten while the unknown one is hashed at twelve, and the difference is
    // roughly fourfold in the direction a stopwatch reads easily.
    expect($unknown)->toBeGreaterThan($known * 0.5)
        ->and($unknown)->toBeLessThan($known * 2);
});

it('locks out a slow guess at one account with a 429 and a Retry-After', function () {
    Customer::factory()->create([
        'email' => 'jennifer@example.com',
        'password' => 'a-long-enough-password',
    ]);

    foreach (range(1, LoginRequest::MAX_ATTEMPTS) as $ignored) {
        $this->postJson('/api/v1/login', [
            'email' => 'jennifer@example.com',
            'password' => 'wrong',
        ])->assertStatus(422);
    }

    // The contract promises 429 and Retry-After for rate limiting, so this
    // must not surface as another 422 the way Laravel's starter kits do.
    $this->postJson('/api/v1/login', [
        'email' => 'jennifer@example.com',
        'password' => 'wrong',
    ])->assertStatus(429)->assertHeader('Retry-After');
});

it('lets the right password through once the guessing stops', function () {
    Customer::factory()->create([
        'email' => 'jennifer@example.com',
        'password' => 'a-long-enough-password',
    ]);

    $this->postJson('/api/v1/login', ['email' => 'jennifer@example.com', 'password' => 'wrong'])
        ->assertStatus(422);

    RateLimiter::clear(Str::transliterate('jennifer@example.com|127.0.0.1'));

    $this->postJson('/api/v1/login', [
        'email' => 'jennifer@example.com',
        'password' => 'a-long-enough-password',
    ])->assertOk();
});

it('signs out of the session', function () {
    Customer::factory()->create([
        'email' => 'jennifer@example.com',
        'password' => 'a-long-enough-password',
    ]);

    // Signed in through the endpoint rather than actingAs, so there is a real
    // session cookie for signing out to actually invalidate.
    $this->postJson('/api/v1/login', [
        'email' => 'jennifer@example.com',
        'password' => 'a-long-enough-password',
    ])->assertOk();

    $this->postJson('/api/v1/logout')->assertNoContent();

    forgetResolvedUser();

    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('deletes only the token that signed out, not every device', function () {
    $customer = Customer::factory()->create();

    $phone = $customer->createToken('phone')->plainTextToken;
    $laptop = $customer->createToken('laptop')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$phone}")
        ->postJson('/api/v1/logout')
        ->assertNoContent();

    expect($customer->tokens()->count())->toBe(1);

    forgetResolvedUser();

    // Signing out of a phone must not sign the same person out of a laptop.
    $this->withHeader('Authorization', "Bearer {$phone}")->getJson('/api/v1/me')->assertUnauthorized();

    forgetResolvedUser();

    $this->withHeader('Authorization', "Bearer {$laptop}")->getJson('/api/v1/me')->assertOk();
});

it('refuses to sign out somebody who is not signed in', function () {
    $this->postJson('/api/v1/logout')->assertUnauthorized();
});

it('keeps identity out of every cache', function () {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'web')
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');
});

/*
| Guard isolation.
|
| Two guards have existed side by side since M1 and this is the first time
| anything has tested them from the customer side, because until now there was
| no customer side. A panel session must never be an API credential, and an API
| token must never open the panel.
*/

it('does not let an admin session authenticate the API', function () {
    $admin = Admin::factory()->create();

    $this->actingAs($admin, 'admin')
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

it('does not let a customer token open the admin panel', function () {
    $customer = Customer::factory()->create();
    $token = $customer->createToken('phone')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->get('/admin')
        ->assertRedirect('/admin/login');
});

/*
| What the frontend is told about the pass.
|
| This exists so the site can choose between "enter the Viewing Room" and "buy
| the pass". It is not the paywall and must never be mistaken for it. The films
| themselves are gated in ViewingRoomTest, which decides the same question again
| on every request.
*/

it('says a customer without the pass does not hold it', function () {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'web')
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('has_viewing_room_access', false);
});

it('says a pass holder holds it', function () {
    $customer = Customer::factory()->withViewingRoomAccess()->create();

    $this->actingAs($customer, 'web')
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('has_viewing_room_access', true);
});

it('changes its answer the moment the pass is granted', function () {
    $customer = Customer::factory()->create();

    $this->actingAs($customer, 'web')
        ->getJson('/api/v1/me')
        ->assertJsonPath('has_viewing_room_access', false);

    $customer->grantViewingRoomAccess();

    forgetResolvedUser();

    $this->actingAs($customer->fresh(), 'web')
        ->getJson('/api/v1/me')
        ->assertJsonPath('has_viewing_room_access', true);
});

it('never says when they were let in, only whether', function () {
    $customer = Customer::factory()->withViewingRoomAccess()->create();

    // The date is for support, not for the website, and a timestamp invites a
    // frontend to do arithmetic on something that is nobody's business.
    $this->actingAs($customer, 'web')
        ->getJson('/api/v1/me')
        ->assertJsonMissingPath('viewing_room_access_at');
});

it('answers the pass question in one place, for the gate and the endpoint alike', function () {
    $withoutPass = Customer::factory()->create();
    $withPass = Customer::factory()->withViewingRoomAccess()->create();

    expect(Gate::forUser($withoutPass)->allows('enter-viewing-room'))->toBeFalse()
        ->and(Gate::forUser($withPass)->allows('enter-viewing-room'))->toBeTrue();

    // And the reason survives, so a refusal can say something useful rather
    // than the framework's "This action is unauthorized."
    expect(Gate::forUser($withoutPass)->inspect('enter-viewing-room')->message())
        ->toBe('This is for pass holders.');
});

it('limits how fast one caller can ask who they are', function () {
    $customer = Customer::factory()->create();

    // Uncacheable and it reads the customer every time, so it was the one
    // authenticated route with nothing in front of it. The ceiling is well
    // above a frontend re-checking who is signed in.
    collect(range(1, 60))->each(
        fn () => $this->actingAs($customer, 'sanctum')->getJson('/api/v1/me')->assertOk()
    );

    $this->actingAs($customer, 'sanctum')->getJson('/api/v1/me')->assertStatus(429);
});
