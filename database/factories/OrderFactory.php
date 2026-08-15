<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'status' => OrderStatus::Pending,
            'currency' => 'USD',

            // Nothing on it yet, so nothing to charge. `forProduct` puts the
            // total right when it adds a line, which is the only way a total
            // should ever be arrived at.
            'total_amount' => 0,
        ];
    }

    /**
     * Put something on the order, priced the way a real purchase would be.
     */
    public function forProduct(Product $product, int $quantity = 1, ?string $question = null): static
    {
        return $this->afterCreating(function (Order $order) use ($product, $quantity, $question): void {
            $order->lines()->create([
                'product_id' => $product->id,
                'product_key' => $product->key,
                'unit_amount' => $product->priceIn($order->currency),
                'quantity' => $quantity,
                'question' => $question,
            ]);

            $order->forceFill([
                'total_amount' => $order->load('lines')->totalOfLines(),
            ])->save();
        });
    }

    public function paid(): static
    {
        return $this->state(fn (): array => [
            'status' => OrderStatus::Paid,
            'paid_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn (): array => ['status' => OrderStatus::Cancelled]);
    }

    /**
     * Paid for, written and sent.
     */
    public function fulfilled(): static
    {
        return $this->paid()->state(fn (): array => ['fulfilled_at' => now()]);
    }

    public function in(string $currency): static
    {
        return $this->state(fn (): array => ['currency' => $currency]);
    }
}
