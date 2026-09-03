<?php

namespace Database\Seeders;

use App\Enums\ProductKey;
use App\Models\Product;
use App\Support\Locales;
use Illuminate\Database\Seeder;
use RuntimeException;

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
 *
 * ## Why this seeds real copy and real prices
 *
 * It used to seed neither: copy started empty, prices started at zero, and the
 * comment on the test said zero was "deliberately wrong rather than plausibly
 * wrong". The reasoning was that availability is calculated, so an unfinished
 * reading stays off the website with nothing to switch off.
 *
 * That reasoning holds and the trade was still wrong. **The shop is what a
 * fresh database was left with none of.** A deploy against an empty database
 * produced a homepage with no products on it, and staging sat in exactly that
 * state — two of five readings visible, the rest invisible because nobody had
 * typed a price. An empty shop is not a safe default. Changed 20 August 2026.
 *
 * ## What "never overwritten" means now
 *
 * Per field, not per row: **anything already holding a value is left exactly as
 * it is, and only what is empty gets filled.** An empty string is not an
 * edit, it is a field nobody has reached yet, so filling it takes nothing away
 * from anybody. Copy reading "fasd" is an edit, however unpromising, and stays.
 *
 * This is what lets the fix reach a database that has already been seeded.
 * Matching on `! $product->exists` would have left every half-finished row on
 * staging exactly as unfinished as it was.
 *
 * ## And it happens exactly once, which is the other half
 *
 * **"Fill what is empty" run on every deploy also means "undo emptying
 * something".** Zeroing a price is the only way the panel offers to take a
 * product off sale — `is_published` is not on the form — and clearing a
 * description is an ordinary edit. Refilling either with a developer's default
 * on the next deploy is the system overruling the person it is supposed to
 * serve, and it would do it silently.
 *
 * So `defaults_seeded_at` records that a product has had its blanks filled, and
 * a stamped product is never touched again. The intent is "give this row the
 * defaults it never had", not "hold these fields non-empty forever".
 *
 * Structure is still re-applied on every run. That part genuinely does belong
 * to code.
 *
 * `is_published` is not touched. It defaults to true and is not editable in the
 * panel — see the 2026_07_28 migration, readings are on the website by default
 * and are never taken off — so filling the copy and the prices is the whole of
 * what makes a product visible.
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

            // Structure, re-applied every run because code owns it, and taken
            // from the catalogue rather than written out here so there is one
            // description of what each product is.
            $product->fill([
                'type' => $key->type(),
                'fulfillment' => $key->fulfillment(),

                // A question is always optional. Every reading accepts one, no
                // reading demands one, and a pass has nothing to ask about.
                'allows_question' => $key->allowsQuestion(),

                // And whether it may be bought for somebody else. Structure for
                // the same reason the line above is: the frontend draws its
                // gift control off this, so turning a reading into a present is
                // a seeder run rather than a frontend release. The two are
                // independent, and `one-card` answers true above and false
                // here.
                'is_giftable' => $key->isGiftable(),
            ]);

            // Position is a value, not structure: the client arranges the shop.
            // Set once so a later run never undoes a reordering.
            if (! $product->exists) {
                $product->sort_order = $key->defaultSortOrder();
            }

            // Once only. See the docblock: past this point the values are the
            // client's, including the empty ones, which are how they take
            // something down.
            if ($product->defaults_seeded_at === null) {
                $this->fillMissingCopy($product, $key);
                $this->fillMissingPrices($product, $key);
                $product->defaults_seeded_at = now();
            }

            $product->save();
        }
    }

    /**
     * Fills any copy field that is still empty, in the original language only.
     *
     * Translations are never seeded. A language is finished by a translator or
     * it is not finished at all, and inventing Spanish here would put a product
     * on the Spanish website in English. See .claude/translation.md section 4.
     */
    private function fillMissingCopy(Product $product, ProductKey $key): void
    {
        $locale = Locales::default();

        $defaults = [
            'name' => $key->defaultName(),
            'short_description' => $key->defaultShortDescription(),
            'long_description' => $key->defaultLongDescription(),
        ];

        foreach ($defaults as $field => $default) {
            // `false` disables spatie's fallback, so this asks what this
            // language actually holds rather than what it would display.
            $current = $product->getTranslation($field, $locale, false);

            if (blank($current) || $this->isSupersededName($field, $current, $key)) {
                $product->setTranslation($field, $locale, $default);
            }
        }
    }

    /**
     * Whether this value is one the seeder itself wrote in an earlier version,
     * and so is a leftover rather than somebody's decision.
     *
     * Only names have any. See `ProductKey::supersededNames`.
     */
    private function isSupersededName(string $field, string $current, ProductKey $key): bool
    {
        return $field === 'name' && in_array($current, $key->supersededNames(), true);
    }

    /**
     * Fills any price still sitting at zero.
     *
     * Zero is the only "unset" a `NOT NULL unsigned` column can express, and
     * nothing is ever sold for nothing, so it is unambiguous: a zero is a price
     * nobody has typed yet. `Product::scopePriced` reads it the same way.
     */
    private function fillMissingPrices(Product $product, ProductKey $key): void
    {
        $defaults = $key->defaultPrices();

        // Driven by the currency list rather than by the defaults, so adding a
        // currency to the config without adding a price for it fails here,
        // loudly and at deploy time, instead of at the first insert with a
        // constraint violation nobody can read.
        foreach (array_keys(config('currencies.available')) as $code) {
            if (! isset($defaults[$code])) {
                throw new RuntimeException(
                    "No default {$code} price for the {$key->value} product. Every currency in "
                    .'config/currencies.php needs one in ProductKey::defaultPrices().'
                );
            }

            $column = 'price_'.strtolower($code);

            if (($product->getAttribute($column) ?? 0) <= 0) {
                $product->setAttribute($column, $defaults[$code]);
            }
        }
    }
}
