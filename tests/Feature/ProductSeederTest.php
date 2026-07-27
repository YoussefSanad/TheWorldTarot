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

it('creates the four readings', function () {
    seedProducts();

    expect(Product::pluck('key')->all())->toBe(ProductKey::readings());
});

it('creates them unpublished, with no price set', function () {
    seedProducts();

    $product = Product::firstWhere('key', ProductKey::OneCard);

    // Zero is deliberately wrong rather than plausibly wrong. A made-up
    // placeholder price is the kind of thing that reaches production unnoticed.
    expect($product->is_published)->toBeFalse()
        ->and($product->price_usd)->toBe(0)
        ->and($product->price_eur)->toBe(0)
        ->and($product->price_gbp)->toBe(0);
});

it('gives every reading the same structure', function () {
    seedProducts();

    Product::all()->each(function (Product $product) {
        expect($product->type)->toBe(ProductType::Reading)
            ->and($product->fulfilment)->toBe(Fulfilment::ManualPdf)
            ->and($product->allows_question)->toBeTrue();
    });
});

it('can be run repeatedly without duplicating anything', function () {
    seedProducts();
    seedProducts();
    seedProducts();

    // It runs on every deploy, so this is the property that matters most.
    expect(Product::count())->toBe(4);
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
