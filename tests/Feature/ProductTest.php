<?php

use App\Enums\Fulfillment;
use App\Enums\ProductKey;
use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stores translatable columns as jsonb, not json', function () {
    $types = collect(DB::select(
        "select column_name, data_type
         from information_schema.columns
         where table_name = 'products'
           and column_name in ('name', 'short_description', 'long_description')"
    ))->pluck('data_type', 'column_name');

    // json is not usefully indexable on Postgres. See .claude/translation.md.
    expect($types)->each->toBe('jsonb')
        ->and($types)->toHaveCount(3);
});

it('has no slug column, since a product is addressed by its key', function () {
    expect(Schema::hasColumn('products', 'slug'))->toBeFalse()
        ->and(Schema::hasTable('slug_history'))->toBeFalse();
});

it('casts the key to the product catalogue enum', function () {
    $product = Product::factory()->create(['key' => ProductKey::OneCard]);

    expect($product->key)->toBe(ProductKey::OneCard)
        ->and($product->fresh()->key)->toBe(ProductKey::OneCard)
        ->and(DB::table('products')->value('key'))->toBe('one-card');
});

it('stores and returns copy per locale', function () {
    $product = Product::factory()->create([
        'name' => ['en' => 'One Card', 'it' => 'Una Carta'],
    ]);

    expect($product->getTranslation('name', 'en'))->toBe('One Card')
        ->and($product->getTranslation('name', 'it'))->toBe('Una Carta');
});

it('falls back to the default locale when a translation is missing', function () {
    $product = Product::factory()->create([
        'name' => ['en' => 'One Card'],
    ]);

    // The safety net in .claude/translation.md section 7, not a designed
    // behaviour: a live locale is complete by definition, so this should never
    // fire in production.
    expect($product->getTranslation('name', 'it'))->toBe('One Card');
});

it('refuses a product with any price missing', function (string $missing) {
    // The whole reason prices are columns rather than a related table. This is
    // enforced by the database, so a seeder, a queued job or a direct write
    // cannot create a product nobody in some country can buy.
    $attributes = Product::factory()->raw();
    unset($attributes[$missing]);

    expect(fn () => DB::table('products')->insert($attributes))
        ->toThrow(QueryException::class);
})->with(['price_usd', 'price_eur', 'price_gbp']);

it('accepts a product with every price set', function () {
    $product = Product::factory()->create([
        'price_usd' => 2500,
        'price_eur' => 2300,
        'price_gbp' => 1900,
    ]);

    expect($product->exists)->toBeTrue();
});

it('returns the price for a currency in integer minor units', function () {
    $product = Product::factory()->create([
        'price_usd' => 2500,
        'price_eur' => 2300,
        'price_gbp' => 1900,
    ]);

    expect($product->priceIn('GBP'))->toBeInt()->toBe(1900)
        ->and($product->priceIn('usd'))->toBe(2500)
        ->and($product->priceIn('EUR'))->toBe(2300);
});

it('returns null for a currency it does not sell in, rather than another currency', function () {
    $product = Product::factory()->create();

    // Never a fallback. A number from the wrong currency is worse than none.
    expect($product->priceIn('JPY'))->toBeNull()
        ->and($product->priceIn('nonsense'))->toBeNull();
});

it('casts type and fulfillment to enums in both directions', function () {
    $product = Product::factory()->pass()->create();

    expect($product->type)->toBe(ProductType::Pass)
        ->and($product->fulfillment)->toBe(Fulfillment::AccessGrant)
        ->and($product->fresh()->type)->toBe(ProductType::Pass);

    expect(DB::table('products')->value('type'))->toBe('pass');
});

it('keeps a soft deleted product so an order can still reference it', function () {
    $product = Product::factory()->create();
    $product->delete();

    expect(Product::find($product->id))->toBeNull()
        ->and(Product::withTrashed()->find($product->id))->not->toBeNull();
});

it('refuses two products with the same key', function () {
    Product::factory()->create(['key' => ProductKey::OneCard]);

    expect(fn () => Product::factory()->create(['key' => ProductKey::OneCard]))
        ->toThrow(QueryException::class);
});

it('is absent from a language it is not finished in', function () {
    $product = Product::factory()->create();

    // Cards have carried this test since M3 and products never had it. A
    // mutation sweep on 23 August 2026 removed the language check from the
    // product declaration entirely and all 715 tests stayed green, which means
    // nothing here would have noticed a reading leaking onto the Spanish site
    // untranslated. It notices now.
    expect($product->isAvailableIn('en'))->toBeTrue()
        ->and($product->isAvailableIn('es'))->toBeFalse()
        ->and(Product::availableIn('es')->pluck('id'))->not->toContain($product->id)
        ->and(Product::availableIn('en')->pluck('id'))->toContain($product->id);
});

it('scopes to published products of a type', function () {
    Product::factory()->create(['key' => ProductKey::OneCard]);
    Product::factory()->unpublished()->create(['key' => ProductKey::ThreeCard]);
    Product::factory()->pass()->create();

    $keys = Product::published()->ofType(ProductType::Reading)->pluck('key');

    expect($keys)->toContain(ProductKey::OneCard)
        ->and($keys)->not->toContain(ProductKey::ThreeCard)
        ->and($keys)->not->toContain(ProductKey::ViewingRoomPass);
});

/*
| The factory answers for the key it was asked for, not the one it was on.
|
| `ProductFactory` rotates a static counter through the four readings so a test
| can make several products without naming each one. Three fields are derived
| from a key, and each of them read the rotation's key rather than the override
| until it was corrected: `is_giftable` first, then `name` and `sort_order` with
| B8 (#74).
|
| **The rotation is process-wide and is never reset**, which is what makes this
| worth a test of its own. Which key a product is derived from depends on how
| many were made before it anywhere in the run, so the defect surfaces as an
| unrelated test failing in a later file when somebody adds one that creates a
| product. That is exactly how `name` was found, and it cost a debugging session
| that started in the wrong file.
|
| **A full rotation is made, and `make` rather than `create`.** Asserting one
| product would only catch the defect when the counter happened to sit on
| another key, which would make this test as order-dependent as the bug it
| guards: the first draft of it was, and passed by luck. Four covers every
| starting offset, since under the defect four consecutive draws are four
| different keys and cannot all agree with the one asked for. `products.key` is
| unique, so four of one key have to be made rather than created.
*/

it('derives every field from the key it was given, whatever the rotation was on', function () {
    // The one product that proves the three fields are independent: it takes a
    // question, it cannot be gifted, and it sorts first.
    foreach (range(1, count(ProductKey::readings())) as $ignored) {
        $product = Product::factory()->make(['key' => ProductKey::OneCard]);

        expect($product->name)->toBe(ProductKey::OneCard->defaultName())
            ->and($product->is_giftable)->toBeFalse()
            ->and($product->sort_order)->toBe(ProductKey::OneCard->defaultSortOrder());
    }

    // And the states, which name their own key rather than being handed one.
    // The pass sorting at a reading's position is what put `sort_order` by hand
    // into two tests that were never about the factory.
    foreach (range(1, count(ProductKey::readings())) as $ignored) {
        $pass = Product::factory()->pass()->make();

        expect($pass->sort_order)->toBe(ProductKey::ViewingRoomPass->defaultSortOrder())
            ->and($pass->is_giftable)->toBeFalse();
    }
});
