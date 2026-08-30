<?php

use App\Actions\ClaimAccount;
use App\Enums\ClaimOutcome;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

uses(RefreshDatabase::class);

/*
| Claiming the account a purchase made for somebody.
|
| The action exists because the condition and the write used to sit in
| different scopes: `SetPasswordController` read "has this account ever had a
| password" through its own query, and the write happened later inside the
| broker's callback. Two claims arriving together both read null, both passed,
| and the loser overwrote the winner.
|
| **The truly concurrent case is proved sequentially here and said so plainly**,
| per .claude/testing.md on anything genuinely impractical to test. A
| two-connection test would prove that Postgres implements `lockForUpdate`,
| which is not our behaviour to prove, and it is exactly the test that goes
| flaky in CI and gets weakened later. What the lock buys is written into
| `ClaimAccount`'s docblock, beside the code that depends on it.
*/

function claimant(): Customer
{
    return Customer::factory()->create([
        'email' => 'jennifer@example.com',
        'password' => null,
    ]);
}

function claim(string $token, string $email = 'jennifer@example.com', string $password = 'a-brand-new-password'): ClaimOutcome
{
    return app(ClaimAccount::class)($email, $token, $password);
}

it('claims an account nobody has claimed', function () {
    $customer = claimant();

    expect(claim(Password::broker('customers')->createToken($customer)))
        ->toBe(ClaimOutcome::Claimed);

    expect(Hash::check('a-brand-new-password', $customer->fresh()->password))->toBeTrue();
});

it('refuses an account that has already been claimed, leaving its password standing', function () {
    $customer = Customer::factory()->create([
        'email' => 'jennifer@example.com',
        'password' => 'the-original-password',
    ]);

    expect(claim(Password::broker('customers')->createToken($customer)))
        ->toBe(ClaimOutcome::AlreadyClaimed);

    expect(Hash::check('the-original-password', $customer->fresh()->password))->toBeTrue();
});

it('answers an address with no account exactly as it answers a claimed one', function () {
    // One outcome for both, because separating them is how this becomes a way
    // to ask whether an address has an account and whether it has been claimed.
    expect(claim('anything', 'nobody@example.com'))->toBe(ClaimOutcome::AlreadyClaimed);
});

it('refuses a token invented from nothing', function () {
    claimant();

    expect(claim('not-a-real-token'))->toBe(ClaimOutcome::LinkNotValid);

    expect(Customer::firstWhere('email', 'jennifer@example.com')->password)->toBeNull();
});

it('refuses a token that has expired', function () {
    $customer = claimant();
    $token = Password::broker('customers')->createToken($customer);

    $this->travel(config('auth.passwords.customers.expire') + 1)->minutes();

    expect(claim($token))->toBe(ClaimOutcome::LinkNotValid);

    expect($customer->fresh()->password)->toBeNull();
});

it('refuses a token belonging to somebody else', function () {
    claimant();
    $other = Customer::factory()->create(['email' => 'someone@example.com', 'password' => null]);

    expect(claim(Password::broker('customers')->createToken($other)))
        ->toBe(ClaimOutcome::LinkNotValid);

    expect(Customer::firstWhere('email', 'jennifer@example.com')->password)->toBeNull();
});

it('lets exactly one of two claims on the same link win', function () {
    $customer = claimant();
    $token = Password::broker('customers')->createToken($customer);

    // **The sequential shape of the race.** Two clicks of one link, which is
    // what a second claim actually looks like: the token is minted once, so
    // both requests carry the same string.
    expect(claim($token, password: 'the-winners-password'))->toBe(ClaimOutcome::Claimed);
    expect(claim($token, password: 'the-losers-password'))->not->toBe(ClaimOutcome::Claimed);

    // The part that would actually hurt. The loser must not overwrite.
    expect(Hash::check('the-winners-password', $customer->fresh()->password))->toBeTrue();
});

it('leaves the loser their token rather than spending it on their behalf', function () {
    $customer = claimant();
    $token = Password::broker('customers')->createToken($customer);

    // Claimed by somebody else after this claimant's link was minted, written
    // straight to the row so nothing here goes through the action being tested.
    DB::table('customers')->where('id', $customer->id)->update([
        'password' => Hash::make('somebody-elses-password'),
    ]);

    expect(claim($token))->toBe(ClaimOutcome::AlreadyClaimed);

    // The refusal rolls back, so the token survives to be refused again rather
    // than being consumed by a claim that never happened.
    expect(Password::broker('customers')->getRepository()->exists($customer->fresh(), $token))
        ->toBeTrue();
});
