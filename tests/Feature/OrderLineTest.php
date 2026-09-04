<?php

use App\Enums\ProductKey;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
| What a line is called, on every screen and in every message that names one.
|
| **Fourteen call sites said this three different ways.** Seven fell back with
| `??`, four with `?:`, one reached for the catalogue's default and two had no
| fallback at all, and the three chains disagreed about what to fall back to as
| well as about when. `product_key->value` is `three-card` and
| `ProductKey::defaultName()` is `3 CARD READING`, so one product was named two
| different things to one customer on two different screens.
|
| **`??` never fires, and that is the defect rather than the untidiness.**
| `Product::$name` is translatable, and `spatie/laravel-translatable` answers
| the empty string, never null, when the read locale and the `en` fallback are
| both blank. So `?:` fell back and `??` did not: a product whose name is blank
| was named in the panel and nameless in the customer's mail.
|
| The empty case below is what pins that down, and it is built by writing an
| empty string into `en` rather than by clearing the column, because the column
| is `jsonb NOT NULL` and a cleared one resolves to the same empty string.
*/

/**
 * A line for a product, bought the way a real purchase buys one.
 *
 * Read back off the order rather than made directly, so the line under test
 * carries the key and the price captured from the catalogue the way a
 * purchased one does.
 */
function aLineFor(Product $product): OrderLine
{
    return Order::factory()->forProduct($product)->create()->lines->first();
}

it('calls a line what the client called the product', function () {
    $line = aLineFor(Product::factory()->create(['name' => ['en' => 'THE MONTH AHEAD']]));

    expect($line->fresh()->displayName())->toBe('THE MONTH AHEAD');
});

it('falls back to the signed-off name when the product has none in any locale', function () {
    $product = Product::factory()->create(['key' => ProductKey::ThreeCard]);
    $product->setTranslation('name', 'en', '')->save();

    $line = aLineFor($product)->fresh();

    // **The catalogue's default, and never the key.** `3 CARD READING` is copy
    // off the homepage tile that the client signed off. `three-card` is a URL
    // slug, and it reached a customer's inbox by accident.
    expect($line->displayName())->toBe('3 CARD READING')
        ->and($line->displayName())->not->toBe(ProductKey::ThreeCard->value);
});

it('still names a reading that has since been retired', function () {
    $product = Product::factory()->create(['name' => ['en' => 'IN DEPTH READING']]);
    $line = aLineFor($product);

    $product->delete();

    // Products soft delete precisely so an order can go on naming them, and
    // `OrderLine::product()` carries `withTrashed()` for it. Retirement alone
    // never reaches the fallback.
    expect($line->fresh()->displayName())->toBe('IN DEPTH READING');
});
