<?php

namespace App\Availability;

use App\Models\Card;
use App\Models\Product;
use App\Support\Locales;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The conditions a card and a product both have.
 *
 * Here rather than on either model, so that the two cannot come to different
 * answers about what "published" or "finished in a language" means. A condition
 * only one of them has is declared on that model instead.
 *
 * @see Card::availability()
 * @see Product::availability()
 */
final class Conditions
{
    /**
     * Switched on.
     *
     * The wording exists for completeness rather than for a screen: nothing in
     * the panel unpublishes a card, and a product's toggle sits on the list
     * rather than the editor. It is here because every condition carries its
     * own words, and a record the gate refuses with nothing to say why is the
     * failure this whole module exists to make impossible.
     */
    public static function published(): Condition
    {
        return Condition::make(
            missing: 'to be published',
            query: fn (Builder $query) => $query->where('is_published', true),
            record: fn (Model $record): bool => (bool) $record->getAttribute('is_published'),
        );
    }

    /**
     * Finished in the original language.
     *
     * The source everything else is written from. A record that is not finished
     * in it is not finished at all, so it appears nowhere rather than existing
     * in Spanish and not in English.
     *
     * Declared separately from the language being read, and it is the reason
     * the panel can now say why a record with perfect Spanish is still not on
     * the Spanish site. It used to answer "nothing" and be wrong.
     */
    public static function finishedInTheOriginalLanguage(): Condition
    {
        return Condition::make(
            missing: fn (Model $record): array => $record->missingCopy(Locales::default()),
            query: fn (Builder $query) => $query->completeIn(Locales::default()),
            record: fn (Model $record): bool => $record->isCompleteIn(Locales::default()),
        );
    }

    /**
     * Finished in the language being read.
     *
     * The same rule as above asked of a different language, which is why they
     * are two uses of one condition rather than two conditions. When the
     * language being read is the original, both ask the same question and she
     * is told about it once.
     */
    public static function finishedInTheLanguageAsked(): Condition
    {
        return Condition::make(
            missing: fn (Model $record, string $locale): array => $record->missingCopy($locale),
            query: fn (Builder $query, string $locale) => $query->completeIn($locale),
            record: fn (Model $record, string $locale): bool => $record->isCompleteIn($locale),
        );
    }

    /**
     * Not removed.
     *
     * Settled deliberately, because the two renderings disagreed here and
     * nothing said so. The query inherited an opinion it never stated, from the
     * soft delete global scope, while the predicate had none, so a removed card
     * was absent from every listing and still answered "yes, on the website"
     * when asked directly.
     *
     * The opinion is now the declaration's, held by both renderings. The query
     * states the exclusion itself rather than borrowing it, so asking
     * `withTrashed()` cannot reopen the gap. Nothing on the live site changes:
     * a removed record was already invisible everywhere it was listed.
     */
    public static function notRemoved(): Condition
    {
        return Condition::make(
            missing: 'a developer to put it back, because it was removed',
            query: fn (Builder $query) => $query->whereNull($query->getModel()->getQualifiedDeletedAtColumn()),
            record: fn (Model $record): bool => ! $record->trashed(),
        );
    }
}
