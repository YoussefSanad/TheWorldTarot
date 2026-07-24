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
