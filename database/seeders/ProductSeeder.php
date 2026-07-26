<?php

namespace Database\Seeders;

use App\Enums\Fulfilment;
use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * The things this site sells.
 *
 * Products are seeded rather than created in the panel, so this file is the
 * definition of what exists. Safe to run on every deploy.
 *
 * The rule it follows is the spine in CLAUDE.md section 4: **structure is
 * owned by code and re-applied on every run, values are owned by the client
 * and never overwritten.** So a corrected fulfilment mode reaches production on
 * the next deploy, while Jennifer's copy and prices are never trampled by it.
 */
class ProductSeeder extends Seeder
{
    /**
     * Prices start at zero, which is deliberately wrong rather than plausibly
     * wrong. A made-up placeholder price is the kind of thing that reaches
     * production unnoticed. Nothing can be published at zero, so the panel
     * makes her set a real one.
     */
    private const PRODUCTS = [
        [
            'key' => 'one-card',
            'name' => 'One Card Reading',
            'sort_order' => 1,
        ],
        [
            'key' => 'three-card',
            'name' => 'Three Card Reading',
            'sort_order' => 2,
        ],
        [
            'key' => 'month-ahead',
            'name' => 'Month Ahead Reading',
            'sort_order' => 3,
        ],
        [
            'key' => 'in-depth',
            'name' => 'In Depth Reading',
            'sort_order' => 4,
        ],
    ];

    public function run(): void
    {
        foreach (self::PRODUCTS as $definition) {
            $product = Product::withTrashed()->firstOrNew(['key' => $definition['key']]);

            // Values, set once so a later run never overwrites an edit.
            if (! $product->exists) {
                $product->fill([
                    'name' => ['en' => $definition['name']],
                    'short_description' => ['en' => ''],
                    'long_description' => ['en' => ''],
                    'slug' => ['en' => $definition['key']],
                    'price_usd' => 0,
                    'price_eur' => 0,
                    'price_gbp' => 0,
                    'is_published' => false,
                    'sort_order' => $definition['sort_order'],
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
