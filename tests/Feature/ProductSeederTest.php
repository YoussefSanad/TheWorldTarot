<?php

use App\Enums\Fulfilment;
use App\Enums\ProductKey;
use App\Enums\ProductType;
use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedProducts(): void
{
    (new ProductSeeder)->run();
}

it('creates the four readings and the viewing room pass', function () {
    seedProducts();

    // Ordered explicitly. Without it Postgres returns rows in whatever order
    // they physically sit in, which shifts as rows are updated, so the same
    // assertion passes or fails depending on what ran before it.
    expect(Product::orderBy('sort_order')->pluck('key')->all())
        ->toBe(ProductKey::seedable());
});

it('creates them with no price and no copy, so nobody can see them yet', function () {
    seedProducts();

    $product = Product::firstWhere('key', ProductKey::OneCard);

    // Zero is deliberately wrong rather than plausibly wrong. A made-up
    // placeholder price is the kind of thing that reaches production unnoticed.
    // Availability is calculated, so an unfinished reading is off the website
    // without anything having to be switched off.
    expect($product->price_usd)->toBe(0)
        ->and($product->price_eur)->toBe(0)
        ->and($product->price_gbp)->toBe(0)
        ->and($product->isAvailableIn('en'))->toBeFalse()
        ->and($product->whatIsMissing('en'))->toContain('a price in every currency');
});

it('takes each product\'s structure from the catalogue', function () {
    seedProducts();

    // Structure belongs to code, and the catalogue is where code says what each
    // product is. The seeder only turns that into rows.
    Product::all()->each(function (Product $product) {
        expect($product->type)->toBe($product->key->type())
            ->and($product->fulfilment)->toBe($product->key->fulfilment())
            ->and($product->allows_question)->toBe($product->key->allowsQuestion());
    });
});

it('makes the pass a pass, and nothing to ask a question about', function () {
    seedProducts();

    $pass = Product::firstWhere('key', ProductKey::ViewingRoomPass);

    // It grants access instead of delivering anything, which is what M5 reads
    // when it stamps the customer.
    expect($pass->type)->toBe(ProductType::Pass)
        ->and($pass->fulfilment)->toBe(Fulfilment::AccessGrant)
        ->and($pass->allows_question)->toBeFalse();
});

it('keeps the pass out of the list of readings', function () {
    seedProducts();

    // One table for everything sellable, and the readings endpoint asks for
    // readings. The pass has a page of its own.
    expect(Product::ofType(ProductType::Reading)->count())->toBe(4)
        ->and(Product::ofType(ProductType::Pass)->count())->toBe(1);
});

it('can be run repeatedly without duplicating anything', function () {
    seedProducts();
    seedProducts();
    seedProducts();

    // It runs on every deploy, so this is the property that matters most.
    expect(Product::count())->toBe(5);
});

it('never overwrites copy or prices the client has edited', function () {
    seedProducts();

    Product::firstWhere('key', ProductKey::OneCard)->update([
        'name' => ['en' => 'A Single Card'],
        'price_gbp' => 1900,
        'is_published' => true,
        'sort_order' => 9,
    ]);

    seedProducts();

    $product = Product::firstWhere('key', ProductKey::OneCard);

    // Values belong to the client. A deploy must never trample them.
    expect($product->getTranslation('name', 'en'))->toBe('A Single Card')
        ->and($product->price_gbp)->toBe(1900)
        ->and($product->is_published)->toBeTrue()
        ->and($product->sort_order)->toBe(9);
});

it('re-applies structure that a developer has corrected', function () {
    seedProducts();

    // Something changed it by hand or by an older seed.
    Product::firstWhere('key', ProductKey::OneCard)->update([
        'fulfilment' => Fulfilment::AccessGrant,
        'allows_question' => false,
    ]);

    seedProducts();

    // Structure belongs to code, so the next deploy puts it back.
    $product = Product::firstWhere('key', ProductKey::OneCard);

    expect($product->fulfilment)->toBe(Fulfilment::ManualPdf)
        ->and($product->allows_question)->toBeTrue();
});

it('does not resurrect a reading that was soft deleted', function () {
    seedProducts();

    $product = Product::firstWhere('key', ProductKey::OneCard);
    $product->delete();

    seedProducts();

    // It matches the trashed row rather than creating a second one, which would
    // collide on the unique key anyway.
    expect(Product::withTrashed()->where('key', ProductKey::OneCard)->count())->toBe(1)
        ->and(Product::where('key', ProductKey::OneCard)->exists())->toBeFalse();
});
