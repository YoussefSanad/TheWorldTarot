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
        return self::publishedRecords()
            ->map(fn (Model $record) => [
                'record' => $record,
                'label' => self::labelFor($record),
                'fields' => $record->missingTranslations($locale),
            ])
            ->filter(fn (array $row) => $row['fields'] !== [])
            ->values();
    }

    /**
     * How far along a language is, for the summary line in the panel.
     *
     * @return array{complete: int, total: int}
     */
    public static function progress(string $locale): array
    {
        $records = self::publishedRecords();

        return [
            'complete' => $records->filter->isCompleteIn($locale)->count(),
            'total' => $records->count(),
        ];
    }

    /**
     * Only records a customer could actually reach. A reading with no price, or
     * one still being written in the original language, is on the website in no
     * language at all, so blocking a translation over it would be nonsense.
     *
     * @return Collection<int, Model>
     */
    private static function publishedRecords(): Collection
    {
        return collect(config('translatable.models'))
            ->flatMap(fn (string $model) => $model::query()
                ->published()
                ->priced()
                // Not finished in the original language means not on the
                // website at all, so it leaves no language short of anything.
                ->completeIn(Locales::default())
                ->get()
                ->all());
    }

    private static function labelFor(Model $record): string
    {
        return (string) $record->getTranslation('name', Locales::default(), false);
    }
}
