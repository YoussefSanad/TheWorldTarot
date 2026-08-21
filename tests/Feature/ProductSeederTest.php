<?php

use App\Enums\Fulfillment;
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

it('creates them finished, so a fresh database has a shop in it', function () {
    seedProducts();

    // Reversed on 20 August 2026. These used to be seeded with empty copy and
    // zero prices, on the grounds that a made-up price might reach production
    // unnoticed. The cost was worse and did reach production: a deploy against
    // an empty database produced a homepage with nothing for sale on it.
    Product::all()->each(function (Product $product) {
        expect($product->isAvailableIn('en'))->toBeTrue()
            ->and($product->whatIsMissing('en'))->toBe([]);
    });
});

it('prices every product in every currency it sells in', function () {
    seedProducts();

    $currencies = array_keys(config('currencies.available'));

    Product::all()->each(function (Product $product) use ($currencies) {
        foreach ($currencies as $code) {
            expect($product->priceIn($code))->toBeGreaterThan(0);
        }
    });
});

it('fills in copy and prices an earlier seed left empty', function () {
    seedProducts();

    // The state staging was actually in: a row created by the version that
    // seeded nothing, so empty throughout and never stamped. Null is what every
    // pre-existing row holds, since the column was added after them.
    Product::firstWhere('key', ProductKey::MonthAhead)->update([
        'short_description' => ['en' => ''],
        'long_description' => ['en' => ''],
        'price_usd' => 0,
        'price_eur' => 0,
        'price_gbp' => 0,
        'defaults_seeded_at' => null,
    ]);

    seedProducts();

    $product = Product::firstWhere('key', ProductKey::MonthAhead);

    // Matching on whether the row exists would have left it exactly as broken.
    expect($product->isAvailableIn('en'))->toBeTrue()
        ->and($product->price_usd)->toBe(ProductKey::MonthAhead->defaultPrices()['USD'])
        ->and($product->defaults_seeded_at)->not->toBeNull();
});

it('leaves a price the client has cleared, so taking something down sticks', function () {
    seedProducts();

    // Zeroing a price is the only lever the panel offers for pulling something
    // off sale: `is_published` is not on the form. Refilling it on the next
    // deploy would overrule that silently, and the client would have no way of
    // knowing why the thing they withdrew came back.
    Product::firstWhere('key', ProductKey::OneCard)->update(['price_usd' => 0]);

    seedProducts();

    $product = Product::firstWhere('key', ProductKey::OneCard);

    expect($product->price_usd)->toBe(0)
        ->and($product->isAvailableIn('en'))->toBeFalse();
});

it('leaves copy the client has cleared', function () {
    seedProducts();

    Product::firstWhere('key', ProductKey::OneCard)
        ->update(['short_description' => ['en' => '']]);

    seedProducts();

    // Same rule as the price. Once a product has had its defaults, its values
    // are the client's, including the empty ones.
    expect(Product::firstWhere('key', ProductKey::OneCard)->getTranslation('short_description', 'en'))
        ->toBe('');
});

it('stamps a product the first time it is given its defaults, and only then', function () {
    seedProducts();

    $first = Product::firstWhere('key', ProductKey::OneCard)->defaults_seeded_at;

    expect($first)->not->toBeNull();

    seedProducts();

    // The stamp is what makes the whole thing once-only, so it must not move.
    expect(Product::firstWhere('key', ProductKey::OneCard)->defaults_seeded_at->equalTo($first))
        ->toBeTrue();
});

it('replaces a name an older version of the seeder invented', function () {
    seedProducts();

    // The state every already-seeded database was in on 20 August 2026: named
    // by the old seeder, from a list that never matched the homepage, and
    // unstamped because that version of the seeder had nothing to stamp with.
    Product::firstWhere('key', ProductKey::OneCard)
        ->update(['name' => ['en' => 'One Card Reading'], 'defaults_seeded_at' => null]);

    seedProducts();

    // Not an edit, so not protected like one. Without this the tiles and the
    // database disagree forever on every database that matters.
    expect(Product::firstWhere('key', ProductKey::OneCard)->getTranslation('name', 'en'))
        ->toBe(ProductKey::OneCard->defaultName())
        ->toBe('1 CARD READING');
});

it('leaves a name the client actually chose', function () {
    seedProducts();

    Product::firstWhere('key', ProductKey::OneCard)
        ->update(['name' => ['en' => 'A Single Card']]);

    seedProducts();

    expect(Product::firstWhere('key', ProductKey::OneCard)->getTranslation('name', 'en'))
        ->toBe('A Single Card');
});

it('treats copy that is merely bad as an edit and leaves it alone', function () {
    seedProducts();

    Product::firstWhere('key', ProductKey::OneCard)
        ->update(['short_description' => ['en' => 'fasd']]);

    seedProducts();

    // Not the seeder's call. An empty field is one nobody has reached; a field
    // with something in it has been reached, however unpromising the something.
    expect(Product::firstWhere('key', ProductKey::OneCard)->getTranslation('short_description', 'en'))
        ->toBe('fasd');
});

it('leaves translations alone entirely', function () {
    seedProducts();

    $product = Product::firstWhere('key', ProductKey::OneCard);

    // Only the original language is ever seeded. Inventing Spanish here would
    // put a product on the Spanish website written in English.
    expect($product->getTranslations('short_description'))->toBe([
        'en' => ProductKey::OneCard->defaultShortDescription(),
    ]);
});

it('takes each product\'s structure from the catalogue', function () {
    seedProducts();

    // Structure belongs to code, and the catalogue is where code says what each
    // product is. The seeder only turns that into rows.
    Product::all()->each(function (Product $product) {
        expect($product->type)->toBe($product->key->type())
            ->and($product->fulfillment)->toBe($product->key->fulfillment())
            ->and($product->allows_question)->toBe($product->key->allowsQuestion());
    });
});

it('makes the pass a pass, and nothing to ask a question about', function () {
    seedProducts();

    $pass = Product::firstWhere('key', ProductKey::ViewingRoomPass);

    // It grants access instead of delivering anything, which is what M5 reads
    // when it stamps the customer.
    expect($pass->type)->toBe(ProductType::Pass)
        ->and($pass->fulfillment)->toBe(Fulfillment::AccessGrant)
        ->and($pass->allows_question)->toBeFalse();
});

it('makes four readings and one pass, and nothing else yet', function () {
    seedProducts();

    // One table for everything sellable, told apart by type. The rush add-on
    // arrives with M5 and is seeded by the milestone that builds it.
    expect(Product::ofType(ProductType::Reading)->count())->toBe(4)
        ->and(Product::ofType(ProductType::Pass)->count())->toBe(1)
        ->and(Product::ofType(ProductType::Addon)->count())->toBe(0);
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
        'fulfillment' => Fulfillment::AccessGrant,
        'allows_question' => false,
    ]);

    seedProducts();

    // Structure belongs to code, so the next deploy puts it back.
    $product = Product::firstWhere('key', ProductKey::OneCard);

    expect($product->fulfillment)->toBe(Fulfillment::ManualPdf)
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
