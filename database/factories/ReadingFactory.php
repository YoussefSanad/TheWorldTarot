<?php

namespace Database\Factories;

use App\Models\OrderLine;
use App\Models\Reading;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reading>
 */
class ReadingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Lazy, so an override wins, for the reason `OrderLineFactory` gives
            // about two lines sharing one product.
            'order_line_id' => fn (): int => OrderLine::factory()->create()->id,

            'gift_id' => null,
            'question' => fake()->sentence().'?',
            'querent_email' => fake()->safeEmail(),
            'querent_name' => fake()->name(),
            'asked_at' => now(),
            'delivered_at' => null,
        ];
    }

    /**
     * Written and sent.
     */
    public function delivered(): static
    {
        return $this->state(fn (): array => ['delivered_at' => now()]);
    }
}
