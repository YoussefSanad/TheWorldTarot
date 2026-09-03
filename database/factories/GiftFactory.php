<?php

namespace Database\Factories;

use App\Models\Gift;
use App\Models\OrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Gift>
 */
class GiftFactory extends Factory
{
    /**
     * **Minted by default, because most tests want a gift somebody could
     * actually spend.** The unminted state is the window between placement and
     * settlement, and a test that wants it says so.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Lazy, so an override wins, for the reason `OrderLineFactory`
            // gives about two lines sharing one product.
            'order_line_id' => fn (): int => OrderLine::factory()->create()->id,

            // Twelve characters of the alphabet B6 mints from, which is
            // Crockford base32 less the glyphs people mistype off a screen.
            // The generator itself is B6's; this only has to be unique and
            // shaped right.
            'code' => strtoupper(fake()->unique()->bothify('????########')),

            'recipient_email' => fake()->safeEmail(),
            'signature' => fake()->firstName(),
            'message' => fake()->sentence(),
            'locale' => 'en',
            'redeemed_at' => null,
        ];
    }

    /**
     * Placed and not yet paid for, which is the only time a gift has no code.
     */
    public function unminted(): static
    {
        return $this->state(fn (): array => ['code' => null]);
    }

    /**
     * Spent. The obligation is discharged and a reading exists.
     */
    public function redeemed(): static
    {
        return $this->state(fn (): array => ['redeemed_at' => now()]);
    }
}
