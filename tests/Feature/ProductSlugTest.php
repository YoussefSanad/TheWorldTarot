<?php

use App\Models\Product;
use App\Models\SlugHistory;
use App\Support\SlugIndex;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('localization.available', [
        'en' => ['label' => 'English', 'native' => 'English'],
        'it' => ['label' => 'Italian', 'native' => 'Italiano'],
    ]);

    // Migrations ran with English alone configured, so Italian has no index
    // yet. Creating it here is exactly what the deploy that enables a language
    // does, and without it the per-locale assertions below would pass for the
    // wrong reason: no index means no uniqueness to violate.
    SlugIndex::createFor('products', 'it');
});

it('refuses two products with the same slug in the same locale', function () {
    Product::factory()->create(['key' => 'first', 'slug' => ['en' => 'one-card']]);

    // Enforced by the expression index, not by application code, so a seeder or
    // a direct write cannot produce two records answering to one URL.
    expect(fn () => Product::factory()->create(['key' => 'second', 'slug' => ['en' => 'one-card']]))
        ->toThrow(QueryException::class);
});

it('allows the same string as a slug in two different locales', function () {
    Product::factory()->create(['key' => 'first', 'slug' => ['en' => 'una-carta']]);
    $second = Product::factory()->create(['key' => 'second', 'slug' => ['it' => 'una-carta']]);

    // Different URLs, so not a collision. Uniqueness is per locale.
    expect($second->exists)->toBeTrue();
});

it('enforces uniqueness in a second locale, not only the default one', function () {
    Product::factory()->create(['key' => 'first', 'slug' => ['it' => 'una-carta']]);

    // The index added when a language is enabled has to work as well as the one
    // built with the table. This is the test that proves it.
    expect(fn () => Product::factory()->create(['key' => 'second', 'slug' => ['it' => 'una-carta']]))
        ->toThrow(QueryException::class);
});

it('allows a slug to be reused once the product holding it is deleted', function () {
    $original = Product::factory()->create(['key' => 'first', 'slug' => ['en' => 'one-card']]);
    $original->delete();

    // The index is partial on deleted_at. A soft deleted product must not hold
    // its slug hostage against a replacement.
    $replacement = Product::factory()->create(['key' => 'second', 'slug' => ['en' => 'one-card']]);

    expect($replacement->exists)->toBeTrue();
});

it('allows several products with no slug at all', function () {
    Product::factory()->addon()->create(['key' => 'rush-24h']);
    $second = Product::factory()->addon()->create(['key' => 'rush-48h']);

    // Add-ons have no page. Absent slugs must not collide with each other.
    expect($second->exists)->toBeTrue();
});

it('finds a product by its slug in the requested locale', function () {
    $product = Product::factory()->create([
        'slug' => ['en' => 'one-card', 'it' => 'una-carta'],
    ]);

    expect(Product::whereSlug('one-card', 'en')->first()?->id)->toBe($product->id)
        ->and(Product::whereSlug('una-carta', 'it')->first()?->id)->toBe($product->id);
});

it('does not find a slug from another locale', function () {
    Product::factory()->create(['slug' => ['en' => 'one-card', 'it' => 'una-carta']]);

    // Serving the right record under the wrong locale's URL is how two
    // languages end up sharing an address and neither ranks.
    expect(Product::whereSlug('una-carta', 'en')->first())->toBeNull()
        ->and(Product::whereSlug('one-card', 'it')->first())->toBeNull();
});

it('matches nothing for a locale that is not configured', function () {
    Product::factory()->create(['slug' => ['en' => 'one-card']]);

    expect(Product::whereSlug('one-card', 'zz')->first())->toBeNull();
});

it('records the previous slug when one changes', function () {
    $product = Product::factory()->create(['slug' => ['en' => 'one-card']]);

    $product->setTranslation('slug', 'en', 'single-card')->save();

    $history = SlugHistory::first();

    expect(SlugHistory::count())->toBe(1)
        ->and($history->locale)->toBe('en')
        ->and($history->slug)->toBe('one-card')
        ->and($history->sluggable->is($product))->toBeTrue();
});

it('records history per locale, leaving unchanged locales alone', function () {
    $product = Product::factory()->create([
        'slug' => ['en' => 'one-card', 'it' => 'una-carta'],
    ]);

    $product->setTranslation('slug', 'it', 'carta-singola')->save();

    expect(SlugHistory::count())->toBe(1)
        ->and(SlugHistory::first()->locale)->toBe('it')
        ->and(SlugHistory::first()->slug)->toBe('una-carta');
});

it('records nothing when a save does not touch the slug', function () {
    $product = Product::factory()->create(['slug' => ['en' => 'one-card']]);

    $product->setTranslation('name', 'en', 'A New Name')->save();
    $product->update(['price_gbp' => 2900]);

    expect(SlugHistory::count())->toBe(0);
});

it('records the old slug when a locale loses its slug entirely', function () {
    $product = Product::factory()->create([
        'slug' => ['en' => 'one-card', 'it' => 'una-carta'],
    ]);

    $product->forgetTranslation('slug', 'it')->save();

    // The Italian URL is now dead and needs to be redirectable later, so
    // removal matters as much as replacement.
    expect(SlugHistory::count())->toBe(1)
        ->and(SlugHistory::first()->slug)->toBe('una-carta');
});

it('keeps a full trail across several changes', function () {
    $product = Product::factory()->create(['slug' => ['en' => 'first']]);

    $product->setTranslation('slug', 'en', 'second')->save();
    $product->setTranslation('slug', 'en', 'third')->save();

    expect(SlugHistory::pluck('slug')->all())->toBe(['first', 'second'])
        ->and($product->fresh()->getTranslation('slug', 'en'))->toBe('third');
});
