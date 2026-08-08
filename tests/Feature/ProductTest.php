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

it('scopes to published products of a type', function () {
    Product::factory()->create(['key' => ProductKey::OneCard]);
    Product::factory()->unpublished()->create(['key' => ProductKey::ThreeCard]);
    Product::factory()->pass()->create();

    $keys = Product::published()->ofType(ProductType::Reading)->pluck('key');

    expect($keys)->toContain(ProductKey::OneCard)
        ->and($keys)->not->toContain(ProductKey::ThreeCard)
        ->and($keys)->not->toContain(ProductKey::ViewingRoomPass);
});
