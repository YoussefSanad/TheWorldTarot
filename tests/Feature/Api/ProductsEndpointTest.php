<?php

use App\Enums\LocaleState;
use App\Enums\ProductKey;
use App\Models\Locale;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the agreed shape for a published reading', function () {
    Product::factory()->create([
        'key' => ProductKey::OneCard,
        'name' => ['en' => 'One Card Reading'],
        'short_description' => ['en' => 'A single card.'],
        'price_gbp' => 1900,
        'sort_order' => 1,
    ]);

    $this->getJson('/api/v1/products', ['CF-IPCountry' => 'GB'])
        ->assertOk()
        ->assertExactJson([
            [
                'key' => 'one-card',
                'type' => 'reading',
                'name' => 'One Card Reading',
                'short_description' => 'A single card.',
                'allows_question' => true,
                'price' => ['currency' => 'GBP', 'amount' => 1900],
            ],
        ]);
});

it('leaves the long copy out of the listing', function () {
    Product::factory()->create(['long_description' => ['en' => 'A very long description.']]);

    // Sending it would bloat every card on a listing page for nothing.
    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonMissingPath('0.long_description');
});

it('includes the long copy on a single product', function () {
    Product::factory()->create([
        'key' => ProductKey::OneCard,
        'long_description' => ['en' => 'A very long description.'],
    ]);

    $this->getJson('/api/v1/products/one-card')
        ->assertOk()
        ->assertJsonPath('long_description', 'A very long description.');
});

it('never exposes the fulfilment mode', function () {
    Product::factory()->create(['key' => ProductKey::OneCard]);

    // A customer has no business knowing how their reading gets written.
    $this->getJson('/api/v1/products/one-card')->assertJsonMissingPath('fulfilment');
    $this->getJson('/api/v1/products')->assertJsonMissingPath('0.fulfilment');
});

it('hides an unpublished reading from the listing', function () {
    Product::factory()->create(['key' => ProductKey::OneCard]);
    Product::factory()->unpublished()->create(['key' => ProductKey::ThreeCard]);

    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.key', 'one-card');
});

it('answers 404 for an unpublished reading rather than 403', function () {
    Product::factory()->unpublished()->create(['key' => ProductKey::OneCard]);

    // Whether an unreleased reading exists is not public information.
    $this->getJson('/api/v1/products/one-card')->assertNotFound();
});

it('answers 404 for a key that has no product yet', function () {
    // The pass is in the catalogue but has no row until M4.
    $this->getJson('/api/v1/products/viewing-room-pass')->assertNotFound();
});

it('answers 404 for a key outside the catalogue', function () {
    $this->getJson('/api/v1/products/nothing-here')->assertNotFound();
});

it('keeps the viewing room pass out of the readings list', function () {
    Product::factory()->create(['key' => ProductKey::OneCard]);
    Product::factory()->pass()->create();

    $this->getJson('/api/v1/products')
        ->assertJsonCount(1)
        ->assertJsonPath('0.key', 'one-card');
});

it('still finds the pass by its own key', function () {
    Product::factory()->pass()->create();

    // It has a page of its own rather than sitting in a list of readings.
    $this->getJson('/api/v1/products/viewing-room-pass')
        ->assertOk()
        ->assertJsonPath('type', 'pass');
});

it('lists readings in the order the client arranged them', function () {
    Product::factory()->create(['key' => ProductKey::MonthAhead, 'sort_order' => 3]);
    Product::factory()->create(['key' => ProductKey::OneCard, 'sort_order' => 1]);
    Product::factory()->create(['key' => ProductKey::ThreeCard, 'sort_order' => 2]);

    $this->getJson('/api/v1/products')
        ->assertJsonPath('0.key', 'one-card')
        ->assertJsonPath('1.key', 'three-card')
        ->assertJsonPath('2.key', 'month-ahead');
});

it('prices in the currency detected from the country', function () {
    Product::factory()->create([
        'key' => ProductKey::OneCard,
        'price_usd' => 2500,
        'price_eur' => 2300,
        'price_gbp' => 1900,
    ]);

    $this->getJson('/api/v1/products/one-card', ['CF-IPCountry' => 'DE'])
        ->assertJsonPath('price', ['currency' => 'EUR', 'amount' => 2300]);

    $this->getJson('/api/v1/products/one-card', ['CF-IPCountry' => 'JP'])
        ->assertJsonPath('price', ['currency' => 'USD', 'amount' => 2500]);
});

it('honours an explicit currency without re-detecting', function () {
    Product::factory()->create(['key' => ProductKey::OneCard, 'price_gbp' => 1900]);

    // A visitor in Germany who chose pounds is charged in pounds.
    $this->getJson('/api/v1/products/one-card?currency=GBP', ['CF-IPCountry' => 'DE'])
        ->assertJsonPath('price', ['currency' => 'GBP', 'amount' => 1900]);
});

it('returns the price as an integer, never a decimal', function () {
    Product::factory()->create(['key' => ProductKey::OneCard, 'price_gbp' => 1905]);

    $price = $this->getJson('/api/v1/products/one-card?currency=GBP')->json('price.amount');

    expect($price)->toBeInt()->toBe(1905);
});

describe('with spanish live', function () {
    beforeEach(function () {
        Locale::where('code', 'es')->update(['state' => LocaleState::Live]);

        Product::factory()->create([
            'key' => ProductKey::OneCard,
            'name' => ['en' => 'One Card Reading', 'es' => 'Lectura de Una Carta'],
            'short_description' => ['en' => 'A single card.', 'es' => 'Una sola carta.'],
        ]);
    });

    it('returns the copy in the requested language', function () {
        $this->getJson('/api/v1/products?locale=es')
            ->assertJsonPath('0.name', 'Lectura de Una Carta')
            ->assertJsonPath('0.short_description', 'Una sola carta.');
    });

    it('keeps the same key in every language', function () {
        // The key is the address, and it is not translated, so a product has
        // one URL rather than one per language.
        $this->getJson('/api/v1/products?locale=es')->assertJsonPath('0.key', 'one-card');

        $this->getJson('/api/v1/products/one-card?locale=es')
            ->assertOk()
            ->assertJsonPath('name', 'Lectura de Una Carta');
    });

    it('prices and translates independently of each other', function () {
        $this->getJson('/api/v1/products/one-card?locale=es', ['CF-IPCountry' => 'GB'])
            ->assertJsonPath('name', 'Lectura de Una Carta')
            ->assertJsonPath('price.currency', 'GBP');
    });
});

it('serves english when a requested language is not public yet', function () {
    // Spanish is seeded as `available`, so a visitor asking for it gets the
    // default rather than a half translated page.
    Product::factory()->create([
        'key' => ProductKey::OneCard,
        'name' => ['en' => 'One Card Reading', 'es' => 'Lectura de Una Carta'],
    ]);

    $this->getJson('/api/v1/products?locale=es')
        ->assertJsonPath('0.name', 'One Card Reading');
});
