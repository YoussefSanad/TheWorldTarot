<?php

use App\Enums\Fulfilment;
use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('stores translatable columns as jsonb, not json', function () {
    $types = collect(DB::select(
        "select column_name, data_type
         from information_schema.columns
         where table_name = 'products'
           and column_name in ('name', 'slug', 'short_description', 'long_description')"
    ))->pluck('data_type', 'column_name');

    // json is not usefully indexable on Postgres, and the per-locale slug
    // indexes in the next step require jsonb. See .claude/translation.md.
    expect($types)->each->toBe('jsonb')
        ->and($types)->toHaveCount(4);
});

it('stores and returns copy per locale', function () {
    $product = Product::factory()->create([
        'name' => ['en' => 'One Card', 'it' => 'Una Carta'],
    ]);

    expect($product->getTranslation('name', 'en'))->toBe('One Card')
        ->and($product->getTranslation('name', 'it'))->toBe('Una Carta');
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

it('casts type and fulfilment to enums in both directions', function () {
    $product = Product::factory()->pass()->create();

    expect($product->type)->toBe(ProductType::Pass)
        ->and($product->fulfilment)->toBe(Fulfilment::AccessGrant)
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
    Product::factory()->create(['key' => 'one-card']);

    expect(fn () => Product::factory()->create(['key' => 'one-card']))
        ->toThrow(QueryException::class);
});

it('allows an add-on with no slug, since it has no page', function () {
    $addon = Product::factory()->addon()->create();

    // No slug in any locale. Note that the translatable accessor reports an
    // empty string rather than null for a missing translation, so anything
    // deciding whether a product has a page must ask for the translations
    // rather than leaning on `$product->slug === null`.
    expect($addon->type)->toBe(ProductType::Addon)
        ->and($addon->getTranslations('slug'))->toBe([])
        ->and($addon->fresh()->getTranslations('slug'))->toBe([]);
});

it('scopes to published products of a type', function () {
    Product::factory()->create(['key' => 'published-reading']);
    Product::factory()->unpublished()->create(['key' => 'draft-reading']);
    Product::factory()->pass()->create(['key' => 'the-pass']);

    $keys = Product::published()->ofType(ProductType::Reading)->pluck('key');

    expect($keys)->toContain('published-reading')
        ->and($keys)->not->toContain('draft-reading')
        ->and($keys)->not->toContain('the-pass');
});
