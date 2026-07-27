<?php

namespace Database\Seeders;

use App\Enums\Fulfilment;
use App\Enums\ProductKey;
use App\Enums\ProductType;
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
 * overwritten.** A corrected fulfilment mode reaches production on the next
 * deploy, while Jennifer's copy and prices are never trampled by it.
 */
class ProductSeeder extends Seeder
{
    public function run(): void
    {
        // Readings only. The viewing room pass arrives with M4 and the rush
        // add-on with M5, each seeded by the milestone that builds it.
        foreach (ProductKey::readings() as $key) {
            $product = Product::withTrashed()->firstOrNew(['key' => $key]);

            // Values, set once so a later run never overwrites an edit.
            // Prices start at zero, which is deliberately wrong rather than
            // plausibly wrong: a made-up placeholder price is the kind of thing
            // that reaches production unnoticed. Nothing can be published at
            // zero, so the panel makes her set a real one.
            if (! $product->exists) {
                $product->fill([
                    'name' => ['en' => $key->defaultName()],
                    'short_description' => ['en' => ''],
                    'long_description' => ['en' => ''],
                    'price_usd' => 0,
                    'price_eur' => 0,
                    'price_gbp' => 0,
                    'is_published' => false,
                    'sort_order' => $key->defaultSortOrder(),
                ]);
            }

            // Structure, re-applied every run because code owns it.
            $product->fill([
                'type' => ProductType::Reading,
                'fulfilment' => Fulfilment::ManualPdf,

                // A question is always optional. Every reading accepts one, and
                // no reading demands one.
                'allows_question' => true,
            ]);

            $product->save();
        }
    }
}
