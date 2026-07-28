<?php

namespace App\Models;

use App\Models\Concerns\HasTranslationCompleteness;
use App\Support\Locales;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * One card of the World Tarot.
 *
 * The 22 Major Arcana are seeded, but unlike products a card is a row Jennifer
 * creates herself. That is a binding FAQ commitment to people who already paid
 * for the pass, not a convenience.
 *
 * Addressed by id, never a slug. A card is a modal opened from the Library, so
 * its identifier is never read by anyone.
 */
#[Fillable([
    'number', 'name', 'short_description', 'long_description', 'is_published',
])]
class Card extends Model
{
    use HasFactory, HasTranslationCompleteness, HasTranslations, SoftDeletes;

    /**
     * @var array<int, string>
     */
    public array $translatable = ['name', 'short_description', 'long_description'];

    /**
     * What a language needs before this card can be shown in it.
     *
     * The same field names as a product, deliberately, so one wording list
     * describes what is missing across both and the two cannot drift.
     *
     * @return array<int, string>
     */
    public static function requiredTranslations(): array
    {
        return ['name', 'short_description', 'long_description'];
    }

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * What a visitor reading the Library in this language can actually see.
     *
     * Thinner than a product's, which also has to be priced in every currency.
     * A card is on the website when it is finished, and that is all.
     */
    public function scopeAvailableIn(Builder $query, string $locale): Builder
    {
        return $query->published()
            // The original language is the source. A card that is not finished
            // in it is not finished at all, so it appears nowhere rather than
            // existing in Spanish and not in English.
            ->completeIn(Locales::default())
            ->completeIn($locale);
    }

    /**
     * A card she has created but not named yet is the ordinary state of a card
     * for as long as it takes to write its copy, and the language gate has to
     * be able to point at it while it is in that state.
     */
    public function translationLabel(): string
    {
        $name = (string) $this->getTranslation('name', Locales::default(), false);

        return $name !== '' ? $name : 'Card '.$this->number;
    }

    public function isAvailableIn(string $locale): bool
    {
        return $this->is_published
            && $this->isCompleteIn(Locales::default())
            && $this->isCompleteIn($locale);
    }
}
