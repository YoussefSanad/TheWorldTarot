<?php

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('allows a request to a protected v1 route with a valid token', function () {
    $customer = Customer::factory()->create();
    $token = $customer->createToken('test-device')->plainTextToken;

    $this->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/user')
        ->assertOk()
        ->assertJsonPath('email', $customer->email);
});

it('rejects a request to a protected v1 route without a token', function () {
    $this->getJson('/api/v1/user')->assertUnauthorized();
});
