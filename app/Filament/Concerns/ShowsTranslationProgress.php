<?php

namespace App\Filament\Concerns;

use App\Models\Locale;
use App\Support\Locales;
use App\Support\TranslationCoverage;

/**
 * How far each language has got through this list.
 *
 * Deferred from M2, when two translatable models made it say nothing worth
 * reading. Twenty-two cards is where it starts earning its place: knowing that
 * Spanish is nineteen cards short is the difference between finishing the
 * translation and wondering why the language will not go live.
 *
 * The original language is not counted. It is what everything else is written
 * from, so "English: 22 of 22" is a line that can only ever say one thing.
 */
trait ShowsTranslationProgress
{
    public function getSubheading(): ?string
    {
        $others = Locales::all()
            ->reject(fn (Locale $locale): bool => $locale->code === Locales::default());

        // One language means nothing to compare, and a summary of the only
        // language there is would be noise on every screen.
        if ($others->isEmpty()) {
            return null;
        }

        $model = static::getResource()::getModel();

        return $others
            ->map(function (Locale $locale) use ($model): string {
                $progress = TranslationCoverage::progressFor($model, $locale->code);

                return "{$locale->english_name}: {$progress['complete']} of {$progress['total']} translated";
            })
            ->join('. ').'.';
    }
}
