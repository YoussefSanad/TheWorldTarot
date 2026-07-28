<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Whether a language is finished across everything that has to be translated.
 *
 * This is the gate on a language going public. It walks the models declared in
 * config/translatable.php, since adding a translatable model is a developer act
 * while adding a language is a row.
 *
 * See .claude/translation.md sections 4 and 5.
 */
final class TranslationCoverage
{
    /**
     * Nothing published is missing anything in this language.
     */
    public static function isComplete(string $locale): bool
    {
        return self::outstanding($locale)->isEmpty();
    }

    /**
     * Every published record still waiting on this language, with the fields it
     * is waiting for, so a refusal can say what to do about it.
     *
     * @return Collection<int, array{record: Model, label: string, fields: array<int, string>}>
     */
    public static function outstanding(string $locale): Collection
    {
        return self::translatableRecords()
            ->map(fn (Model $record) => [
                'record' => $record,
                'label' => $record->translationLabel(),
                'fields' => $record->missingTranslations($locale),
            ])
            ->filter(fn (array $row) => $row['fields'] !== [])
            ->values();
    }

    /**
     * How far along a language is across everything, for the languages screen.
     *
     * @return array{complete: int, total: int}
     */
    public static function progress(string $locale): array
    {
        return collect(config('translatable.models'))
            ->map(fn (string $model): array => self::progressFor($model, $locale))
            ->reduce(fn (array $running, array $model): array => [
                'complete' => $running['complete'] + $model['complete'],
                'total' => $running['total'] + $model['total'],
            ], ['complete' => 0, 'total' => 0]);
    }

    /**
     * How far along a language is in one kind of thing, for the line above a
     * list.
     *
     * Separate from the total deliberately. "20 of 26" on the languages screen
     * answers whether a language can go live, and answers nothing at all while
     * standing in front of the cards.
     *
     * @param  class-string  $model
     * @return array{complete: int, total: int}
     */
    public static function progressFor(string $model, string $locale): array
    {
        $records = $model::query()->published()->get();

        return [
            'complete' => $records->filter->isCompleteIn($locale)->count(),
            'total' => $records->count(),
        ];
    }

    /**
     * Every record that exists, not only the ones a customer can reach today.
     *
     * This used to skip readings with no price, on the grounds that nobody
     * could see them anyway. That was wrong, and it let a language go public
     * with two of four readings holding no Spanish at all: they were unpriced,
     * so they did not count, so the gate declared Spanish finished.
     *
     * Nothing here is ever retired. A reading with no price is one still being
     * set up, not one that does not count, and it would reach the website long
     * after anyone remembered it was never translated.
     *
     * @return Collection<int, Model>
     */
    private static function translatableRecords(): Collection
    {
        return collect(config('translatable.models'))
            ->flatMap(fn (string $model) => $model::query()->published()->get()->all());
    }
}
