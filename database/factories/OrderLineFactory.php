<?php

namespace Database\Factories;

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
        $product = Product::factory()->create();

        return [
            'order_id' => Order::factory(),
            'product_id' => $product->id,

            // Captured from the product rather than invented, so a line built
            // by a factory is shaped like one built by a purchase.
            'product_key' => $product->key,
            'unit_amount' => $product->price_usd,
            'quantity' => 1,
            'question' => null,
        ];
    }

    public function asking(string $question): static
    {
        return $this->state(fn (): array => ['question' => $question]);
    }
}
