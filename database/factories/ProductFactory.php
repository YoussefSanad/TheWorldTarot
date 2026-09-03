<?php

namespace Database\Factories;

use App\Enums\Fulfillment;
use App\Enums\ProductKey;
use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Which reading the next factory call uses.
     *
     * Keys come from a fixed catalogue, so they cannot be invented per test.
     * Cycling through the readings means a test can make several products
     * without naming each one, and asking for a fifth reading fails on the
     * unique key, which is correct: there is no fifth reading.
     */
    private static int $nextReading = 0;

    /**
     * Define the model's default state.
     *
     * Every price is set, because the database will not accept a row without
     * all three.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $readings = ProductKey::readings();
        $key = $readings[self::$nextReading % count($readings)];
        self::$nextReading++;

        return [
            'key' => $key,
            'type' => ProductType::Reading,
            'fulfillment' => Fulfillment::ManualPdf,
            'allows_question' => true,

            // From the catalogue rather than a flat true, because the four
            // readings do not agree: `one-card` takes a question and cannot be
            // gifted. A factory that flattened this would make the one product
            // that proves the two fields are independent behave like the three
            // that do not.
            //
            // **Off the final key and not `$key`.** Almost every caller
            // overrides the key, and the rotation above has already chosen a
            // different one by then, so reading `$key` here would answer for a
            // product nobody asked for: `one-card` would come out giftable
            // whenever the rotation happened to land on a reading that is. A
            // closure is handed the attributes after overrides are merged,
            // which is the only place the real key is known.
            'is_giftable' => fn (array $attributes): bool => self::keyOf($attributes)->isGiftable(),

            // **Off the final key too, and for the reason `is_giftable` above
            // gives.** ~~`['en' => $key->defaultName()]`~~ read the rotation's
            // key rather than the overridden one, so a product asked for by key
            // came out wearing another reading's name: `three-card` was named
            // THE MONTH AHEAD whenever the rotation happened to sit there.
            //
            // **It was invisible until a test created one more product.** The
            // rotation is static and process-wide, so which name a product gets
            // depends on how many were made before it anywhere in the run. B8
            // (#74) added a test that makes one, every product after it shifted
            // by one, and `SettleAGiftOrderTest`'s assertion that Jennifer is
            // not shown the gifted line started failing on a name it never
            // meant to match. Corrected 3 September 2026.
            'name' => fn (array $attributes): array => ['en' => self::keyOf($attributes)->defaultName()],
            'short_description' => ['en' => fake()->sentence()],
            'long_description' => ['en' => fake()->paragraph()],
            'price_usd' => 2500,
            'price_eur' => 2300,
            'price_gbp' => 1900,
            'is_published' => true,
            'sort_order' => $key->defaultSortOrder(),
        ];
    }

    /**
     * The product a set of attributes is for, however the key was given.
     *
     * An override may arrive as the enum or as the string behind it, since both
     * are accepted everywhere else a key is.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function keyOf(array $attributes): ProductKey
    {
        $key = $attributes['key'];

        return $key instanceof ProductKey ? $key : ProductKey::from((string) $key);
    }

    public function unpublished(): static
    {
        return $this->state(fn () => ['is_published' => false]);
    }

    public function pass(): static
    {
        return $this->state(fn () => [
            'key' => ProductKey::ViewingRoomPass,
            'type' => ProductType::Pass,
            'fulfillment' => Fulfillment::AccessGrant,
            'allows_question' => false,
            'is_giftable' => false,
            'name' => ['en' => ProductKey::ViewingRoomPass->defaultName()],
        ]);
    }

    public function addon(): static
    {
        return $this->state(fn () => [
            'key' => ProductKey::Rush24h,
            'type' => ProductType::Addon,
            'allows_question' => false,
            'is_giftable' => false,
            'name' => ['en' => ProductKey::Rush24h->defaultName()],
        ]);
    }
}
