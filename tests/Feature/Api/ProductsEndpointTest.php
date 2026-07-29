<?php

use App\Enums\LocaleStatus;
use App\Enums\ProductKey;
use App\Models\Admin;
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

    $this->getJson('/api/v1/en/products', ['CF-IPCountry' => 'GB'])
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
    $this->getJson('/api/v1/en/products')
        ->assertOk()
        ->assertJsonMissingPath('0.long_description');
});

it('includes the long copy on a single product', function () {
    Product::factory()->create([
        'key' => ProductKey::OneCard,
        'long_description' => ['en' => 'A very long description.'],
    ]);

    $this->getJson('/api/v1/en/products/one-card')
        ->assertOk()
        ->assertJsonPath('long_description', 'A very long description.');
});

it('never exposes the fulfilment mode', function () {
    Product::factory()->create(['key' => ProductKey::OneCard]);

    // A customer has no business knowing how their reading gets written.
    $this->getJson('/api/v1/en/products/one-card')->assertJsonMissingPath('fulfilment');
    $this->getJson('/api/v1/en/products')->assertJsonMissingPath('0.fulfilment');
});

it('hides an unpublished reading from the listing', function () {
    Product::factory()->create(['key' => ProductKey::OneCard]);
    Product::factory()->unpublished()->create(['key' => ProductKey::ThreeCard]);

    $this->getJson('/api/v1/en/products')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.key', 'one-card');
});

it('answers 404 for an unpublished reading rather than 403', function () {
    Product::factory()->unpublished()->create(['key' => ProductKey::OneCard]);

    // Whether an unreleased reading exists is not public information.
    $this->getJson('/api/v1/en/products/one-card')->assertNotFound();
});

it('answers 404 for a key that has no product yet', function () {
    // The pass is in the catalogue but has no row until M4.
    $this->getJson('/api/v1/en/products/viewing-room-pass')->assertNotFound();
});

it('answers 404 for a key outside the catalogue', function () {
    $this->getJson('/api/v1/en/products/nothing-here')->assertNotFound();
});

it('keeps the viewing room pass out of the readings list', function () {
    Product::factory()->create(['key' => ProductKey::OneCard]);
    Product::factory()->pass()->create();

    $this->getJson('/api/v1/en/products')
        ->assertJsonCount(1)
        ->assertJsonPath('0.key', 'one-card');
});

it('still finds the pass by its own key', function () {
    Product::factory()->pass()->create();

    // It has a page of its own rather than sitting in a list of readings.
    $this->getJson('/api/v1/en/products/viewing-room-pass')
        ->assertOk()
        ->assertJsonPath('type', 'pass');
});

it('lists readings in the order the client arranged them', function () {
    Product::factory()->create(['key' => ProductKey::MonthAhead, 'sort_order' => 3]);
    Product::factory()->create(['key' => ProductKey::OneCard, 'sort_order' => 1]);
    Product::factory()->create(['key' => ProductKey::ThreeCard, 'sort_order' => 2]);

    $this->getJson('/api/v1/en/products')
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

    $this->getJson('/api/v1/en/products/one-card', ['CF-IPCountry' => 'DE'])
        ->assertJsonPath('price', ['currency' => 'EUR', 'amount' => 2300]);

    $this->getJson('/api/v1/en/products/one-card', ['CF-IPCountry' => 'JP'])
        ->assertJsonPath('price', ['currency' => 'USD', 'amount' => 2500]);
});

it('honours an explicit currency without re-detecting', function () {
    Product::factory()->create(['key' => ProductKey::OneCard, 'price_gbp' => 1900]);

    // A visitor in Germany who chose pounds is charged in pounds.
    $this->getJson('/api/v1/en/products/one-card?currency=GBP', ['CF-IPCountry' => 'DE'])
        ->assertJsonPath('price', ['currency' => 'GBP', 'amount' => 1900]);
});

it('returns the price as an integer, never a decimal', function () {
    Product::factory()->create(['key' => ProductKey::OneCard, 'price_gbp' => 1905]);

    $price = $this->getJson('/api/v1/en/products/one-card?currency=GBP')->json('price.amount');

    expect($price)->toBeInt()->toBe(1905);
});

describe('with spanish live', function () {
    beforeEach(function () {
        Locale::where('code', 'es')->update(['status' => LocaleStatus::Live]);

        // Fully translated, since anything less is deliberately not served in
        // Spanish at all.
        Product::factory()->create([
            'key' => ProductKey::OneCard,
            'name' => ['en' => 'One Card Reading', 'es' => 'Lectura de Una Carta'],
            'short_description' => ['en' => 'A single card.', 'es' => 'Una sola carta.'],
            'long_description' => ['en' => 'The long one.', 'es' => 'La larga.'],
        ]);
    });

    it('returns the copy in the requested language', function () {
        $this->getJson('/api/v1/es/products')
            ->assertJsonPath('0.name', 'Lectura de Una Carta')
            ->assertJsonPath('0.short_description', 'Una sola carta.');
    });

    it('keeps the same key in every language', function () {
        // The key is the address, and it is not translated, so a product has
        // one URL rather than one per language.
        $this->getJson('/api/v1/es/products')->assertJsonPath('0.key', 'one-card');

        $this->getJson('/api/v1/es/products/one-card')
            ->assertOk()
            ->assertJsonPath('name', 'Lectura de Una Carta');
    });

    it('prices and translates independently of each other', function () {
        $this->getJson('/api/v1/es/products/one-card', ['CF-IPCountry' => 'GB'])
            ->assertJsonPath('name', 'Lectura de Una Carta')
            ->assertJsonPath('price.currency', 'GBP');
    });

    it('takes the language down when an untranslated reading appears', function () {
        Product::factory()->create([
            'key' => ProductKey::ThreeCard,
            'name' => ['en' => 'Three Card Reading'],
        ]);

        // A language shows everything or nothing, so this is not a shorter
        // Spanish list. There is no Spanish site until it is translated.
        $this->getJson('/api/v1/es/products')->assertNotFound();
    });

    it('keeps that same product visible in english', function () {
        Product::factory()->create([
            'key' => ProductKey::ThreeCard,
            'name' => ['en' => 'Three Card Reading'],
        ]);

        // A missing Spanish translation must never hold back the English site.
        $this->getJson('/api/v1/en/products')->assertJsonCount(2);

        $this->getJson('/api/v1/en/products/three-card')
            ->assertOk()
            ->assertJsonPath('name', 'Three Card Reading');
    });

    it('takes the language down for a half translated reading too', function () {
        // Half translated is the case that would otherwise put an English
        // paragraph on a Spanish page.
        Product::factory()->create([
            'key' => ProductKey::ThreeCard,
            'name' => ['en' => 'Three Card Reading', 'es' => 'Lectura de Tres Cartas'],
            'short_description' => ['en' => 'Three cards.', 'es' => 'Tres cartas.'],
        ]);

        $this->getJson('/api/v1/es/products')->assertNotFound();
        $this->getJson('/api/v1/es/products/three-card')->assertNotFound();
    });
});

it('answers 404 for a language that is not public yet', function () {
    // Spanish ships as a draft. A path segment is an address, not a hint, so
    // asking for a language that is not public is asking for a page that does
    // not exist. Answering in English would put the same content at as many
    // URLs as someone cared to invent.
    Product::factory()->create([
        'key' => ProductKey::OneCard,
        'name' => ['en' => 'One Card Reading', 'es' => 'Lectura de Una Carta'],
    ]);

    $this->getJson('/api/v1/es/products')->assertNotFound();
    $this->getJson('/api/v1/es/products/one-card')->assertNotFound();
});

it('answers 404 for a language that does not exist at all', function () {
    $this->getJson('/api/v1/fr/products')->assertNotFound();
});

it('serves a language in preview to an admin and to nobody else', function () {
    Locale::where('code', 'es')->update(['status' => LocaleStatus::Preview]);

    Product::factory()->create([
        'key' => ProductKey::OneCard,
        'name' => ['en' => 'One Card Reading', 'es' => 'Lectura de Una Carta'],
        'short_description' => ['en' => 'A single card.', 'es' => 'Una sola carta.'],
        'long_description' => ['en' => 'The long one.', 'es' => 'La larga.'],
    ]);

    // This is how the whole site gets reviewed in a language before customers
    // can reach it.
    $this->getJson('/api/v1/es/products')->assertNotFound();

    $this->actingAs(Admin::factory()->create(), 'admin')
        ->getJson('/api/v1/es/products')
        ->assertOk()
        ->assertJsonPath('0.name', 'Lectura de Una Carta');
});

it('does not list the viewing room pass among the readings', function () {
    Product::factory()->create(['key' => ProductKey::OneCard]);
    Product::factory()->pass()->create();

    // It is sellable, priced, and written, and it still does not belong in a
    // list of readings. It has a page of its own and is fetched by its key.
    $this->getJson('/api/v1/en/products')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.key', 'one-card');
});

it('serves the pass by its own key once it is finished', function () {
    Product::factory()->pass()->create([
        'name' => ['en' => 'Viewing Room Pass'],
        'short_description' => ['en' => 'Every Living Tarot film.'],
        'long_description' => ['en' => 'The longer pitch.'],
    ]);

    $this->getJson('/api/v1/en/products/viewing-room-pass')
        ->assertOk()
        ->assertJsonPath('type', 'pass')
        ->assertJsonPath('allows_question', false);
});
