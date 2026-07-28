<?php

namespace App\Models\Concerns;

use App\Support\Locales;
use Illuminate\Database\Eloquent\Builder;

/**
 * Whether a record is finished in a language, worked out from the data rather
 * than from a stored flag.
 *
 * A flag would be stale the moment someone edited the content it approved, and
 * could be ticked over an empty field. This cannot drift. What it cannot see is
 * quality: copy reading "TODO" counts as complete, which is what the `preview`
 * status exists to catch. See .claude/translation.md section 4.
 *
 * The model declares which fields a language needs through
 * `requiredTranslations()`, so the same list drives the publish guard.
 */
trait HasTranslationCompleteness
{
    /**
     * @return array<int, string>
     */
    abstract public static function requiredTranslations(): array;

    /**
     * What to call this record when telling someone it is unfinished.
     *
     * The name in the original language, which is what everything else is
     * written from. A record with no name yet has nothing to be called, so
     * models that can exist in that state override this with something else,
     * rather than leaving a refusal that reads " needs a name".
     */
    public function translationLabel(): string
    {
        return (string) $this->getTranslation('name', Locales::default(), false);
    }

    /**
     * Every language is checked, the default one included.
     *
     * It used to be exempt, on the grounds that the publish guard covered
     * English. That guard is gone, so English is now held to the same standard
     * as everything else: a reading with no description is not ready to be read
     * in any language.
     */
    public function isCompleteIn(string $locale): bool
    {
        return $this->missingTranslations($locale) === [];
    }

    /**
     * Which fields this language is still waiting for, for an error message
     * that says what to do rather than that something is wrong.
     *
     * @return array<int, string>
     */
    public function missingTranslations(string $locale): array
    {
        return array_values(array_filter(
            static::requiredTranslations(),
            fn (string $field): bool => blank($this->getTranslation($field, $locale, false)),
        ));
    }

    /**
     * Only records a visitor in this language should see.
     *
     * Filtering happens in the query rather than after it, so a listing cannot
     * accidentally leak an untranslated record by forgetting to check. The
     * default language passes everything through.
     */
    public function scopeCompleteIn(Builder $query, string $locale): Builder
    {
        foreach (static::requiredTranslations() as $field) {
            // jsonb ->> returns null for a missing key, so both a missing
            // translation and an empty one are caught here.
            $query->whereRaw("nullif(trim({$field}->>?), '') is not null", [$locale]);
        }

        return $query;
    }
}
