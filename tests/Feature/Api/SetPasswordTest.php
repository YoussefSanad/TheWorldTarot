<?php

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost');
});

function unclaimed(): Customer
{
    return Customer::factory()->create([
        'email' => 'jennifer@example.com',
        'password' => null,
    ]);
}

function claimPayload(string $token, array $overrides = []): array
{
    return array_merge([
        'token' => $token,
        'email' => 'jennifer@example.com',
        'password' => 'a-brand-new-password',
        'password_confirmation' => 'a-brand-new-password',
    ], $overrides);
}

it('lets somebody claim the account made when they bought something', function () {
    $customer = unclaimed();

    $this->postJson('/api/v1/set-password', claimPayload(passwordTokenFor($customer)))
        ->assertOk();

    expect(Hash::check('a-brand-new-password', $customer->fresh()->password))->toBeTrue();

    $this->postJson('/api/v1/login', [
        'email' => 'jennifer@example.com',
        'password' => 'a-brand-new-password',
    ])->assertOk();
});

/**
 * The shape `API_CONTRACT.md` promises the frontend partner, asserted on the
 * wire rather than on the model.
 *
 * **A claimed buyer is the account that was never asked a name.** Since ADR
 * 0002 the hosted page collects a text field the buyer types and the wallet is
 * mounted asking for an address rather than a billing contact, so settlement
 * makes the account and nobody asks. Issue #52.
 *
 * **The claim itself answers a message and no customer**, so the null name is
 * met on the sign in that follows it and on `/me` after that. Walking from the
 * claim rather than starting at a bare factory is what makes that true here
 * rather than assumed, and it was written the other way first.
 *
 * The two settlement suites assert the column. Nothing asserted what
 * `CustomerResource` does with it, which is the half the partner types
 * against, and their `ApiCustomer` still declares `name: string`.
 */
it('answers a claimed account that was never asked a name with a null one', function () {
    $customer = Customer::factory()->create([
        'name' => null,
        'email' => 'jennifer@example.com',
        'password' => null,
    ]);

    $this->postJson('/api/v1/set-password', claimPayload(passwordTokenFor($customer)))
        ->assertOk();

    // Present and null, not absent. A missing key and a null one are different
    // things to a client that destructures the body.
    $this->postJson('/api/v1/login', [
        'email' => 'jennifer@example.com',
        'password' => 'a-brand-new-password',
    ])->assertOk()
        ->assertJsonPath('name', null)
        ->assertJsonPath('email', 'jennifer@example.com');

    // Not the local part of the address, which is what this used to answer.
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('name', null);
});

it('works once and then never again', function () {
    $customer = unclaimed();
    $payload = claimPayload(passwordTokenFor($customer));

    $this->postJson('/api/v1/set-password', $payload)->assertOk();

    // The account now has a password, so this link is worth nothing even
    // inside the hour it is valid for, and even to the person it was sent to.
    $this->postJson('/api/v1/set-password', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('cannot be used to change a password on an account that already has one', function () {
    $claimed = Customer::factory()->create([
        'email' => 'jennifer@example.com',
        'password' => 'the-original-password',
    ]);

    // A real token, correctly issued, and still refused. Changing a password
    // after claiming is a reset, which needs a fresh link to a mailbox somebody
    // is currently reading.
    $this->postJson('/api/v1/set-password', claimPayload(passwordTokenFor($claimed)))
        ->assertStatus(422);

    expect(Hash::check('the-original-password', $claimed->fresh()->password))->toBeTrue();
});

it('answers an unknown address the same way as a claimed one', function () {
    Customer::factory()->create(['email' => 'jennifer@example.com', 'password' => 'a-password']);

    $claimedAccount = $this->postJson('/api/v1/set-password', claimPayload('anything'));
    $noAccount = $this->postJson('/api/v1/set-password', claimPayload('anything', [
        'email' => 'nobody@example.com',
    ]));

    // Otherwise this becomes a way to ask whether an address has an account,
    // and whether that account has been claimed.
    expect($claimedAccount->status())->toBe($noAccount->status())
        ->and($claimedAccount->json())->toBe($noAccount->json());
});

it('refuses an invented token', function () {
    unclaimed();

    $this->postJson('/api/v1/set-password', claimPayload('not-a-real-token'))
        ->assertStatus(422);

    expect(Customer::firstWhere('email', 'jennifer@example.com')->password)->toBeNull();
});

it('refuses a token that has expired', function () {
    $customer = unclaimed();
    $token = passwordTokenFor($customer);

    $this->travel(config('auth.passwords.customers.expire') + 1)->minutes();

    $this->postJson('/api/v1/set-password', claimPayload($token))->assertStatus(422);

    expect($customer->fresh()->password)->toBeNull();
});

it('holds the first password to the same rules as every other', function () {
    $customer = unclaimed();

    $this->postJson('/api/v1/set-password', claimPayload(passwordTokenFor($customer), [
        'password' => 'short',
        'password_confirmation' => 'short',
    ]))->assertStatus(422)->assertJsonValidationErrors('password');
});

it('refuses somebody else\'s token', function () {
    unclaimed();
    $other = Customer::factory()->create(['email' => 'someone@example.com', 'password' => null]);

    $this->postJson('/api/v1/set-password', claimPayload(passwordTokenFor($other)))
        ->assertStatus(422);

    expect(Customer::firstWhere('email', 'jennifer@example.com')->password)->toBeNull();
});
