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
| The rule and why it reads for emptiness rather than null are
| `OrderLine::displayName()`'s to state, and `.claude/translation.md` section 6
| is the standard behind it: a missing translation reads as an empty string, so
| the seven sites that fell back with `??` never fell back at all.
|
| **Here rather than in `tests/Unit`**, which is where `.claude/testing.md`
| routes a translation fallback. This one cannot be isolated from the framework:
| the empty state only exists once a `jsonb` column has been written and read
| back, so it needs the database, as every other model test here does.
*/

/**
 * A line for a product, bought the way a real purchase buys one.
 *
 * Read back off the order rather than made directly, so the line under test
 * carries the key and the price captured from the catalogue the way a
 * purchased one does. Sole rather than first: these orders hold one line, and
 * a second arriving should fail here rather than quietly name the wrong one.
 */
function aLineFor(Product $product): OrderLine
{
    return Order::factory()->forProduct($product)->create()->lines->sole();
}

it('calls a line what the client called the product', function () {
    $line = aLineFor(Product::factory()->create(['name' => ['en' => 'THE MONTH AHEAD']]));

    expect($line->fresh()->displayName())->toBe('THE MONTH AHEAD');
});

it('falls back to the signed-off name when the product has none in any locale', function () {
    $line = aLineFor(aProductWithNoNameLeft())->fresh();

    // **The catalogue's default, and never the key.** `3 CARD READING` is copy
    // off the homepage tile that the client signed off. `three-card` is a URL
    // slug, and it reached a customer's inbox by accident.
    expect($line->displayName())->toBe('3 CARD READING')
        ->and($line->displayName())->not->toBe(ProductKey::ThreeCard->value);
});

it('treats a name somebody typed a space into as no name at all', function () {
    // **Wider than the empty string, deliberately.** Jennifer types these by
    // hand, and a name of one space renders as the same missing word as a name
    // of nothing. `filled()` is what makes the two behave alike.
    $line = aLineFor(Product::factory()->create(['key' => ProductKey::MonthAhead, 'name' => ['en' => '   ']]));

    expect($line->fresh()->displayName())->toBe('MONTH AHEAD');
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
