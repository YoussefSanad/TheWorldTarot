<?php

namespace Database\Seeders;

use App\Enums\ProductKey;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * The things this site sells.
 *
 * Products are seeded rather than created in the panel, so the ProductKey enum
 * is the definition of what exists and this turns it into rows. Safe to run on
 * every deploy.
 *
 * The rule it follows is the spine in CLAUDE.md section 4: **structure is owned
 * by code and re-applied on every run, values are owned by the client and never
 * overwritten.** A corrected fulfillment mode reaches production on the next
 * deploy, while Jennifer's copy and prices are never trampled by it.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // The four readings and the viewing room pass, which joined on
        // 30 July 2026. The rush add-on arrives with M5, seeded by the
        // milestone that builds it.
        foreach (ProductKey::seedable() as $key) {
            $product = Product::withTrashed()->firstOrNew(['key' => $key]);

            // Values, set once so a later run never overwrites an edit.
            // Prices start at zero and the copy starts empty, which is what
            // keeps a freshly seeded reading off the website: availability is
            // calculated from the copy and the prices, so there is nothing to
            // switch on and nothing to leave switched on by mistake.
            if (! $product->exists) {
                $product->fill([
                    'name' => ['en' => $key->defaultName()],
                    'short_description' => ['en' => ''],
                    'long_description' => ['en' => ''],
                    'price_usd' => 0,
                    'price_eur' => 0,
                    'price_gbp' => 0,
                    'sort_order' => $key->defaultSortOrder(),
                ]);
            }

            // Structure, re-applied every run because code owns it, and taken
            // from the catalogue rather than written out here so there is one
            // description of what each product is.
            $product->fill([
                'type' => $key->type(),
                'fulfillment' => $key->fulfillment(),

                // A question is always optional. Every reading accepts one, no
                // reading demands one, and a pass has nothing to ask about.
                'allows_question' => $key->allowsQuestion(),
            ]);

            $product->save();
        }
    }
}
