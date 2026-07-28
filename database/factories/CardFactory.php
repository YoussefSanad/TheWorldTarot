<?php

namespace Database\Factories;

use App\Models\Card;
use App\Support\Locales;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Card>
 */
class CardFactory extends Factory
{
    /**
     * Which number the next card takes.
     *
     * Numbers are unique, so a test making several cards without naming each
     * one needs them handed out rather than faked. Nothing here depends on the
     * real Major Arcana numbering, which belongs to the seeder.
     */
    private static int $nextNumber = 0;

    /**
     * Define the model's default state.
     *
     * Finished in the default language, since most tests want a card that is
     * on the website and the ones that do not say so explicitly.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = self::$nextNumber++;

        return [
            'number' => $number,
            'name' => ['en' => 'Card '.$number],
            'short_description' => ['en' => fake()->sentence()],
            'long_description' => ['en' => fake()->paragraph()],
            'image_path' => 'cards/card-'.$number.'.jpg',
            'is_published' => true,
        ];
    }

    /**
     * A card whose picture has not been uploaded yet, which is how every card
     * starts.
     */
    public function withoutImage(): static
    {
        return $this->state(fn (): array => ['image_path' => null]);
    }

    public function unpublished(): static
    {
        return $this->state(fn (): array => ['is_published' => false]);
    }

    /**
     * A card nobody has written the copy for yet, which is what every card
     * looks like the moment it is created.
     *
     * Empty arrays rather than nulls: assigning null to a translatable
     * attribute stores a key holding nothing, which is not the same as having
     * no translations at all.
     */
    public function withoutCopy(): static
    {
        return $this->state(fn (): array => [
            'name' => [],
            'short_description' => [],
            'long_description' => [],
        ]);
    }

    /**
     * Finished in another language as well.
     *
     * Applied to the made model rather than as a state, deliberately. A state
     * closure sees the copy as it stands when the state runs, so
     * `translatedInto('es')->create(['name' => ...])` would translate the
     * default name and then have the whole column overwritten, leaving a card
     * that claims to be translated and is not. Tests built on that would pass
     * for the wrong reason.
     */
    public function translatedInto(string ...$locales): static
    {
        return $this->afterMaking(function (Card $card) use ($locales): void {
            foreach (Card::requiredTranslations() as $field) {
                $original = (string) $card->getTranslation($field, Locales::default(), false);

                if ($original === '') {
                    continue;
                }

                foreach ($locales as $locale) {
                    $card->setTranslation($field, $locale, $original.' ('.$locale.')');
                }
            }
        });
    }
}
