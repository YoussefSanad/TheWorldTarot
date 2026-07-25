<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the agreed shape', function () {
    // The exact contract in .claude/api.md.
    $this->getJson('/api/v1/currencies')
        ->assertOk()
        ->assertExactJson([
            'available' => [
                ['code' => 'USD', 'symbol' => '$'],
                ['code' => 'EUR', 'symbol' => '€'],
                ['code' => 'GBP', 'symbol' => '£'],
            ],
            'detected' => 'USD',
        ]);
});

it('detects the currency from the cloudflare country header', function () {
    $this->getJson('/api/v1/currencies', ['CF-IPCountry' => 'GB'])
        ->assertJsonPath('detected', 'GBP');

    $this->getJson('/api/v1/currencies', ['CF-IPCountry' => 'FR'])
        ->assertJsonPath('detected', 'EUR');
});

it('falls back to the default currency for an unmapped country', function () {
    $this->getJson('/api/v1/currencies', ['CF-IPCountry' => 'JP'])
        ->assertJsonPath('detected', 'USD');
});

it('honours an explicit currency without re-detecting', function () {
    // A visitor in Germany who chose pounds keeps pounds. This is the value the
    // product page and checkout both read, so re-detecting here would show one
    // currency and charge another.
    $this->getJson('/api/v1/currencies?currency=GBP', ['CF-IPCountry' => 'DE'])
        ->assertJsonPath('detected', 'GBP');
});

it('ignores a currency it does not sell in and detects instead', function () {
    $this->getJson('/api/v1/currencies?currency=JPY', ['CF-IPCountry' => 'GB'])
        ->assertJsonPath('detected', 'GBP');
});

it('lists exactly the currencies in config', function () {
    config()->set('currencies.available', [
        'USD' => ['symbol' => '$', 'label' => 'US Dollar'],
        'GBP' => ['symbol' => '£', 'label' => 'Pound Sterling'],
    ]);

    $this->getJson('/api/v1/currencies')
        ->assertJsonCount(2, 'available')
        ->assertJsonPath('available.0.code', 'USD')
        ->assertJsonPath('available.1.code', 'GBP');
});

it('is readable without authentication', function () {
    $this->getJson('/api/v1/currencies')->assertOk();
});
