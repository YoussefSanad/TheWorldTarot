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
            'name' => ['en' => $key->defaultName()],
            'short_description' => ['en' => fake()->sentence()],
            'long_description' => ['en' => fake()->paragraph()],
            'price_usd' => 2500,
            'price_eur' => 2300,
            'price_gbp' => 1900,
            'is_published' => true,
            'sort_order' => $key->defaultSortOrder(),
        ];
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
            'name' => ['en' => ProductKey::ViewingRoomPass->defaultName()],
        ]);
    }

    public function addon(): static
    {
        return $this->state(fn () => [
            'key' => ProductKey::Rush24h,
            'type' => ProductType::Addon,
            'allows_question' => false,
            'name' => ['en' => ProductKey::Rush24h->defaultName()],
        ]);
    }
}
