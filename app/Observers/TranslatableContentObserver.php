<?php

namespace App\Observers;

use App\Enums\LocaleStatus;
use App\Support\Locales;
use App\Support\TranslationCoverage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Takes a language off the website the moment something appears on it that is
 * not translated into that language.
 *
 * The rule is that a language shows everything or nothing. Publishing a reading
 * in English while Spanish is live would otherwise leave the Spanish site a
 * reading short, and a customer browsing it would never know what they were not
 * being shown.
 *
 * The consequence is deliberate and worth knowing: adding a reading takes the
 * Spanish site down until its Spanish copy is written. Decided 28 July 2026,
 * replacing a rule that hid the untranslated record instead.
 */
class TranslatableContentObserver
{
    public function saved(Model $record): void
    {
        $this->withdrawLanguagesLeftBehind();
    }

    public function deleted(Model $record): void
    {
        // Deleting cannot make a language incomplete, but restoring one can,
        // and a soft delete is an update away from being undone.
        $this->withdrawLanguagesLeftBehind();
    }

    private function withdrawLanguagesLeftBehind(): void
    {
        foreach (Locales::live() as $locale) {
            if ($locale->code === Locales::default()) {
                continue;
            }

            if (TranslationCoverage::isComplete($locale->code)) {
                continue;
            }

            $locale->update(['status' => LocaleStatus::Draft]);
            Locales::forget();

            // Loud, because the site just changed for every customer reading it
            // in that language.
            Log::warning('Language taken off the website: content is not translated into it.', [
                'locale' => $locale->code,
                'outstanding' => TranslationCoverage::outstanding($locale->code)
                    ->map(fn (array $row): array => [
                        'record' => $row['label'],
                        'missing' => $row['fields'],
                    ])->all(),
            ]);
        }
    }
}
