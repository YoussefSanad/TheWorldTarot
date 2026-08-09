<?php

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| Accounts that exist but have never been claimed.
|
| `customers.password` is nullable so buying something can make an account from
| the email given at checkout, before anybody has chosen a password. That is
| convenient, and it is exactly the kind of change that quietly makes an
| authentication check pass.
|
| These assert the behaviour rather than the guard that implements it. Removing
| the null check in SessionController on 9 August 2026 left them green, because
| Hash::check against null already refuses. That is the right thing to test and
| worth knowing: the guard is insurance, not the protection.
*/

beforeEach(function () {
    $this->withHeader('Origin', 'http://localhost');
});

it('never lets an account with no password be signed in to', function () {
    $unclaimed = Customer::factory()->create(['password' => null]);

    expect($unclaimed->password)->toBeNull();

    // Not the empty string, not the literal word, not anything.
    foreach (['', 'null', 'password', 'a-long-enough-password'] as $attempt) {
        $this->postJson('/api/v1/login', [
            'email' => $unclaimed->email,
            'password' => $attempt,
        ])->assertStatus(422);
    }

    expect(auth('web')->check())->toBeFalse();
});

it('does not let an unclaimed account reset its way in without the mail', function () {
    $unclaimed = Customer::factory()->create(['password' => null]);

    // A reset needs a token that only ever reaches the mailbox, so an account
    // with no password is no easier to take over than any other.
    $this->postJson('/api/v1/reset-password', [
        'token' => 'invented',
        'email' => $unclaimed->email,
        'password' => 'a-long-enough-password',
        'password_confirmation' => 'a-long-enough-password',
    ])->assertStatus(422);

    expect($unclaimed->fresh()->password)->toBeNull();
});

it('gives an unclaimed account nothing that a claimed one would have', function () {
    $unclaimed = Customer::factory()->create(['password' => null]);

    expect($unclaimed->hasViewingRoomAccess())->toBeFalse()
        ->and($unclaimed->tokens()->count())->toBe(0);
});
