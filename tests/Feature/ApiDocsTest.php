<?php

use App\Models\Admin;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('hides the documentation from anonymous visitors', function () {
    // The page enumerates the whole API surface including auth routes, so it is
    // not public outside local development.
    $this->get('/docs/api')->assertForbidden();
    $this->get('/docs/api.json')->assertForbidden();
});

it('hides the documentation from a signed-in customer', function () {
    // The default guard is the customer one, so this is the case a gate written
    // against the passed user would wrongly allow.
    $this->actingAs(Customer::factory()->create())
        ->get('/docs/api')
        ->assertForbidden();
});

it('shows the documentation to a signed-in admin', function () {
    $this->actingAs(Admin::factory()->create(), 'admin')
        ->get('/docs/api')
        ->assertOk();
});

it('generates a specification describing both public endpoints', function () {
    $spec = $this->actingAs(Admin::factory()->create(), 'admin')
        ->get('/docs/api.json')
        ->assertOk()
        ->json();

    expect($spec['paths'])->toHaveKeys(['/v1/languages', '/v1/currencies']);
});

it('documents the language response as a bare array, matching the contract', function () {
    // The contract is an unwrapped array. If someone removes LanguageCollection
    // and reverts to a data wrapper, the spec sent to the frontend changes shape
    // here as well as in the response.
    $spec = $this->actingAs(Admin::factory()->create(), 'admin')
        ->get('/docs/api.json')
        ->json();

    expect($spec['components']['schemas']['LanguageCollection']['type'])->toBe('array')
        ->and($spec['components']['schemas']['LanguageResource']['properties'])
        ->toHaveKeys(['code', 'label'])
        ->and($spec['components']['schemas']['LanguageResource']['properties'])
        ->not->toHaveKey('enabled');
});

it('documents the currency parameters that middleware resolves', function () {
    // These cannot be inferred from the controller signature, so their presence
    // in the spec is what stops them being invisible to the frontend.
    $spec = $this->actingAs(Admin::factory()->create(), 'admin')
        ->get('/docs/api.json')
        ->json();

    $names = collect($spec['paths']['/v1/currencies']['get']['parameters'])->pluck('name');

    expect($names)->toContain('currency')->toContain('CF-IPCountry');
});
