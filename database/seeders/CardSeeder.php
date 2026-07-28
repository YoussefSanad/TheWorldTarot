<?php

namespace Database\Seeders;

use App\Models\Card;
use Illuminate\Database\Seeder;

/**
 * The 22 Major Arcana.
 *
 * Cards are the one thing Jennifer creates herself, so this is not a definition
 * of what may exist, the way the product catalogue is. It is a starting deck,
 * placed once so nobody has to type 22 rows, and everything about a card
 * afterwards belongs to her, its name included.
 *
 * That is why nothing here is re-applied on a later run: unlike a product,
 * which has a type and a fulfilment mode that code owns and corrects, a card
 * has no structural value at all beyond existing. Safe to run on every deploy.
 */
class CardSeeder extends Seeder
{
    /**
     * The Rider-Waite ordering, with Strength at 8 and Justice at 11.
     *
     * Some decks swap those two. Both the numbering and the titles are values
     * she can edit in the panel, so a deck that differs is a correction rather
     * than a deploy.
     *
     * @var array<int, string>
     */
    private const MAJOR_ARCANA = [
        0 => 'The Fool',
        1 => 'The Magician',
        2 => 'The High Priestess',
        3 => 'The Empress',
        4 => 'The Emperor',
        5 => 'The Hierophant',
        6 => 'The Lovers',
        7 => 'The Chariot',
        8 => 'Strength',
        9 => 'The Hermit',
        10 => 'Wheel of Fortune',
        11 => 'Justice',
        12 => 'The Hanged Man',
        13 => 'Death',
        14 => 'Temperance',
        15 => 'The Devil',
        16 => 'The Tower',
        17 => 'The Star',
        18 => 'The Moon',
        19 => 'The Sun',
        20 => 'Judgement',
        21 => 'The World',
    ];

    public function run(): void
    {
        foreach (self::MAJOR_ARCANA as $number => $name) {
            // Trashed rows included, so a card she deleted deliberately stays
            // deleted rather than reappearing on the next deploy. Saving a
            // trashed model does not restore it.
            $card = Card::withTrashed()->firstOrNew(['number' => $number]);

            if ($card->exists) {
                continue;
            }

            // The copy starts empty on purpose. A card is on the website only
            // once it is written, so a freshly seeded deck is invisible without
            // anything having to be switched off, and the language gate can
            // already name every card that is waiting on her.
            $card->fill([
                'name' => ['en' => $name],
                'short_description' => [],
                'long_description' => [],
            ])->save();
        }
    }
}
