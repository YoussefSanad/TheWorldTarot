<?php

namespace Database\Factories;

use App\Enums\Fulfilment;
use App\Enums\ProductType;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * Every price is set, because the database will not accept a row without
     * all three. That is the rule this factory exists to respect rather than
     * work around.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'key' => Str::slug($name),
            'type' => ProductType::Reading,
            'fulfilment' => Fulfilment::ManualPdf,
            'allows_question' => true,
            'slug' => ['en' => Str::slug($name)],
            'name' => ['en' => Str::title($name)],
            'short_description' => ['en' => fake()->sentence()],
            'long_description' => ['en' => fake()->paragraph()],
            'price_usd' => 2500,
            'price_eur' => 2300,
            'price_gbp' => 1900,
            'is_published' => true,
            'sort_order' => 0,
        ];
    }

    public function unpublished(): static
    {
        return $this->state(fn () => ['is_published' => false]);
    }

    public function pass(): static
    {
        return $this->state(fn () => [
            'type' => ProductType::Pass,
            'fulfilment' => Fulfilment::AccessGrant,
            'allows_question' => false,
        ]);
    }

    /**
     * An add-on has no page, so it has no slug.
     */
    public function addon(): static
    {
        return $this->state(fn () => [
            'type' => ProductType::Addon,
            'allows_question' => false,
            // No translations at all, rather than null. Assigning null to a
            // translatable attribute stores {"en": null}, which is a slug entry
            // holding nothing rather than an absent slug.
            'slug' => [],
        ]);
    }
}
