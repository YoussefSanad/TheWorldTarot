<?php

namespace Database\Factories;

use App\Enums\VideoStatus;
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

    /**
     * A card in the Viewing Room: video prepared, poster uploaded.
     */
    public function watchable(): static
    {
        return $this->state(fn (): array => [
            'bunny_video_id' => fake()->uuid(),
            'video_status' => VideoStatus::Ready,
            'video_master_path' => 'masters/'.fake()->uuid().'.mp4',
            'poster_path' => 'posters/'.fake()->uuid().'.jpg',
            'video_uploaded_at' => now(),
        ]);
    }

    /**
     * A first video, handed to Bunny and not finished. The state a card sits in
     * for a few minutes after its first upload, and the one most likely to be
     * forgotten when writing a test about what a customer can watch.
     */
    public function videoProcessing(): static
    {
        return $this->watchable()->state(fn (): array => [
            'bunny_video_id' => null,
            'bunny_incoming_video_id' => fake()->uuid(),
            'video_status' => VideoStatus::Processing,
        ]);
    }

    public function videoFailed(): static
    {
        return $this->videoProcessing()->state(fn (): array => [
            'video_status' => VideoStatus::Failed,
        ]);
    }

    /**
     * Watchable now, with a replacement being prepared.
     *
     * The case the two columns exist for: she uploaded the wrong file, uploaded
     * the right one, and nobody should notice a gap in between.
     */
    public function videoBeingReplaced(): static
    {
        return $this->watchable()->state(fn (): array => [
            'bunny_incoming_video_id' => fake()->uuid(),
            'video_status' => VideoStatus::Processing,
        ]);
    }

    /**
     * A video with no poster frame, which is watchable in every sense except
     * the one that counts.
     */
    public function withoutPoster(): static
    {
        return $this->watchable()->state(fn (): array => ['poster_path' => null]);
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
