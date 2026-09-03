<?php

use App\Enums\LocaleStatus;
use App\Enums\ProductKey;
use App\Models\Admin;
use App\Models\Locale;
use App\Models\Product;
use Database\Seeders\ProductSeeder;
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
                'is_giftable' => false,
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

it('never exposes the fulfillment mode', function () {
    Product::factory()->create(['key' => ProductKey::OneCard]);

    // A customer has no business knowing how their reading gets written.
    $this->getJson('/api/v1/en/products/one-card')->assertJsonMissingPath('fulfillment');
    $this->getJson('/api/v1/en/products')->assertJsonMissingPath('0.fulfillment');
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

it('lists the viewing room pass alongside the readings', function () {
    Product::factory()->create(['key' => ProductKey::OneCard, 'sort_order' => 1]);
    Product::factory()->pass()->create(['sort_order' => 5]);

    // Changed 20 August 2026. The homepage sells all of it in one section, so
    // one list returns all of it. `type` is what tells them apart.
    $this->getJson('/api/v1/en/products')
        ->assertJsonCount(2)
        ->assertJsonPath('0.key', 'one-card')
        ->assertJsonPath('0.type', 'reading')
        ->assertJsonPath('1.key', 'viewing-room-pass')
        ->assertJsonPath('1.type', 'pass');
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

it('never lists an add-on, even a finished one', function () {
    Product::factory()->create(['key' => ProductKey::OneCard]);

    // The rush upgrade is bought alongside a reading and has no page of its
    // own, so it has no business in a list of things to buy. It has no row
    // until M5, which is why this is pinned now: whoever adds it to
    // `ProductKey::seedable()` will not think to look at this controller.
    Product::factory()->addon()->create([
        'name' => ['en' => '24 Hour Rush'],
        'short_description' => ['en' => 'Faster.'],
        'long_description' => ['en' => 'The longer pitch.'],
    ]);

    $this->getJson('/api/v1/en/products')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.key', 'one-card');
});

it('holds the pass to the same completeness rules as a reading', function () {
    Product::factory()->create(['key' => ProductKey::OneCard]);

    // Listed like everything else, and gated like everything else: an
    // unfinished pass is off the website exactly as an unfinished reading is.
    Product::factory()->pass()->create(['long_description' => ['en' => '']]);

    $this->getJson('/api/v1/en/products')
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.key', 'one-card');
});

it('serves the whole seeded catalogue, so the shop is never empty', function () {
    // The property the seeder exists for, checked end to end rather than at the
    // model: a deploy against an empty database must produce a shop, not a
    // homepage with nothing on it. See ProductSeeder's docblock.
    (new ProductSeeder)->run();

    $response = $this->getJson('/api/v1/en/products')->assertOk();

    expect($response->json())->toHaveCount(count(ProductKey::seedable()));

    // Four readings and the pass, each with copy and a real price.
    foreach ($response->json() as $product) {
        expect($product['name'])->not->toBe('')
            ->and($product['short_description'])->not->toBe('')
            ->and($product['price']['amount'])->toBeGreaterThan(0);
    }
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

it('says whether a product may be bought for somebody else', function () {
    Product::factory()->create(['key' => ProductKey::MonthAhead]);

    // **Drawn from this field and never from a list the frontend holds**,
    // `API_CONTRACT.md:114`. A hardcoded `GIFT A READING` toggle is a button
    // that 422s on submit, which is the failure this field exists to prevent.
    $this->getJson('/api/v1/en/products/month-ahead')
        ->assertOk()
        ->assertJsonPath('is_giftable', true);
});

it('says a one card reading may not be given away, though it takes a question', function () {
    Product::factory()->create(['key' => ProductKey::OneCard]);

    // The product that proves the two fields are independent. A frontend
    // reading either one off the other draws the wrong control here.
    $this->getJson('/api/v1/en/products/one-card')
        ->assertOk()
        ->assertJsonPath('allows_question', true)
        ->assertJsonPath('is_giftable', false);
});

it('says the pass may not be given away', function () {
    Product::factory()->pass()->create();

    $this->getJson('/api/v1/en/products/viewing-room-pass')
        ->assertOk()
        ->assertJsonPath('is_giftable', false);
});
