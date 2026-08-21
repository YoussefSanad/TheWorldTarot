<?php

namespace Database\Factories;

use App\Enums\ProductKey;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OrderLine>
 */
class OrderLineFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),

            // **Lazy, so an override wins.** Creating a product eagerly made
            // one even when the caller passed `product_id` themselves, which
            // meant two lines sharing a single product died on
            // `products_key_unique` while asking for nothing unusual.
            'product_id' => fn (): int => Product::factory()->create()->id,

            // Read back from whichever product `product_id` settled on, given
            // or made. A line built by a factory is then shaped like one built
            // by a purchase, where these are captured from the catalogue rather
            // than invented, and a factory cannot produce a line whose key
            // disagrees with its product.
            'product_key' => fn (array $line): ProductKey => Product::findOrFail($line['product_id'])->key,
            'unit_amount' => fn (array $line): int => Product::findOrFail($line['product_id'])->price_usd,

            'quantity' => 1,
            'question' => null,
        ];
    }

    public function asking(string $question): static
    {
        return $this->state(fn (): array => ['question' => $question]);
    }
}
