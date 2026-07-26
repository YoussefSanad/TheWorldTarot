<?php

use App\Enums\LocaleState;
use App\Models\Locale;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns the agreed shape for a published reading', function () {
    Product::factory()->create([
        'key' => 'one-card',
        'name' => ['en' => 'One Card Reading'],
        'short_description' => ['en' => 'A single card.'],
        'slug' => ['en' => 'one-card'],
        'price_gbp' => 1900,
        'sort_order' => 1,
    ]);

    $this->getJson('/api/v1/products', ['CF-IPCountry' => 'GB'])
        ->assertOk()
        ->assertExactJson([
            [
                'slug' => 'one-card',
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
        'slug' => ['en' => 'one-card'],
        'long_description' => ['en' => 'A very long description.'],
    ]);

    $this->getJson('/api/v1/products/one-card')
        ->assertOk()
        ->assertJsonPath('long_description', 'A very long description.');
});

it('never exposes the internal key or fulfilment mode', function () {
    Product::factory()->create(['slug' => ['en' => 'one-card']]);

    // A customer has no business knowing how their reading gets written.
    $this->getJson('/api/v1/products/one-card')
        ->assertJsonMissingPath('key')
        ->assertJsonMissingPath('fulfilment');

    $this->getJson('/api/v1/products')
        ->assertJsonMissingPath('0.key')
        ->assertJsonMissingPath('0.fulfilment');
});

it('hides an unpublished reading from the listing', function () {
    Product::factory()->create(['key' => 'live-one', 'slug' => ['en' => 'live-one']]);
    Product::factory()->unpublished()->create(['key' => 'draft', 'slug' => ['en' => 'draft']]);

    $this->getJson('/api/v1/products')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.slug', 'live-one');
});

it('answers 404 for an unpublished reading rather than 403', function () {
    Product::factory()->unpublished()->create(['slug' => ['en' => 'draft']]);

    // Whether an unreleased reading exists is not public information.
    $this->getJson('/api/v1/products/draft')->assertNotFound();
});

it('answers 404 for a slug that does not exist', function () {
    $this->getJson('/api/v1/products/nothing-here')->assertNotFound();
});

it('keeps the viewing room pass out of the readings list', function () {
    Product::factory()->create(['key' => 'a-reading', 'slug' => ['en' => 'a-reading']]);
    Product::factory()->pass()->create(['key' => 'the-pass', 'slug' => ['en' => 'viewing-room']]);

    $this->getJson('/api/v1/products')
        ->assertJsonCount(1)
        ->assertJsonPath('0.slug', 'a-reading');
});

it('still finds the pass by its own slug', function () {
    Product::factory()->pass()->create(['slug' => ['en' => 'viewing-room']]);

    // It has a page of its own rather than sitting in a list of readings.
    $this->getJson('/api/v1/products/viewing-room')
        ->assertOk()
        ->assertJsonPath('type', 'pass');
});

it('lists readings in the order the client arranged them', function () {
    Product::factory()->create(['key' => 'third', 'slug' => ['en' => 'third'], 'sort_order' => 3]);
    Product::factory()->create(['key' => 'first', 'slug' => ['en' => 'first'], 'sort_order' => 1]);
    Product::factory()->create(['key' => 'second', 'slug' => ['en' => 'second'], 'sort_order' => 2]);

    $this->getJson('/api/v1/products')
        ->assertJsonPath('0.slug', 'first')
        ->assertJsonPath('1.slug', 'second')
        ->assertJsonPath('2.slug', 'third');
});

it('prices in the currency detected from the country', function () {
    Product::factory()->create([
        'slug' => ['en' => 'one-card'],
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
    Product::factory()->create([
        'slug' => ['en' => 'one-card'],
        'price_gbp' => 1900,
    ]);

    // A visitor in Germany who chose pounds is charged in pounds.
    $this->getJson('/api/v1/products/one-card?currency=GBP', ['CF-IPCountry' => 'DE'])
        ->assertJsonPath('price', ['currency' => 'GBP', 'amount' => 1900]);
});

it('returns the price as an integer, never a decimal', function () {
    Product::factory()->create(['slug' => ['en' => 'one-card'], 'price_gbp' => 1905]);

    $price = $this->getJson('/api/v1/products/one-card?currency=GBP')->json('price.amount');

    expect($price)->toBeInt()->toBe(1905);
});

describe('with spanish live', function () {
    beforeEach(function () {
        Locale::where('code', 'es')->update(['state' => LocaleState::Live]);

        Product::factory()->create([
            'key' => 'one-card',
            'name' => ['en' => 'One Card Reading', 'es' => 'Lectura de Una Carta'],
            'short_description' => ['en' => 'A single card.', 'es' => 'Una sola carta.'],
            'slug' => ['en' => 'one-card', 'es' => 'una-carta'],
        ]);
    });

    it('returns the copy in the requested language', function () {
        $this->getJson('/api/v1/products?locale=es')
            ->assertJsonPath('0.name', 'Lectura de Una Carta')
            ->assertJsonPath('0.short_description', 'Una sola carta.')
            ->assertJsonPath('0.slug', 'una-carta');
    });

    it('finds a product by its slug in that language', function () {
        $this->getJson('/api/v1/products/una-carta?locale=es')
            ->assertOk()
            ->assertJsonPath('name', 'Lectura de Una Carta');
    });

    it('does not find a slug from another language', function () {
        $this->getJson('/api/v1/products/una-carta')->assertNotFound();
        $this->getJson('/api/v1/products/one-card?locale=es')->assertNotFound();
    });

    it('prices and translates independently of each other', function () {
        $this->getJson('/api/v1/products/una-carta?locale=es', ['CF-IPCountry' => 'GB'])
            ->assertJsonPath('name', 'Lectura de Una Carta')
            ->assertJsonPath('price.currency', 'GBP');
    });
});

it('serves english when a requested language is not public yet', function () {
    // Spanish is seeded as `available`, so a visitor asking for it gets the
    // default rather than a half translated page.
    Product::factory()->create([
        'name' => ['en' => 'One Card Reading', 'es' => 'Lectura de Una Carta'],
        'slug' => ['en' => 'one-card', 'es' => 'una-carta'],
    ]);

    $this->getJson('/api/v1/products?locale=es')
        ->assertJsonPath('0.name', 'One Card Reading')
        ->assertJsonPath('0.slug', 'one-card');
});
