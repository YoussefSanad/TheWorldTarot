<?php

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('persists a customer through the factory into the customers table', function () {
    $customer = Customer::factory()->create([
        'email' => 'reader@example.com',
    ]);

    expect($customer->exists)->toBeTrue();
    $this->assertDatabaseHas('customers', ['email' => 'reader@example.com']);
});

it('resolves the default auth provider to the Customer model', function () {
    expect(config('auth.providers.customers.model'))->toBe(Customer::class);
});

/*
| The unclaimed account.
|
| The concept a purchase creates, a claim link is worth sending to, and a claim
| link works exactly once against. Named here because three modules used to
| spell it out as a comparison and agree only by hand.
*/

it('calls an account with no password unclaimed', function () {
    $customer = Customer::factory()->create(['password' => null]);

    expect($customer->isUnclaimed())->toBeTrue();
});

it('stops calling an account unclaimed once it has a password', function () {
    $customer = Customer::factory()->create(['password' => 'a-long-enough-password']);

    expect($customer->isUnclaimed())->toBeFalse();
});
