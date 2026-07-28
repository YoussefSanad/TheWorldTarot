<?php

namespace App\Filament\Concerns;

use App\Support\Locales;
use Illuminate\Validation\ValidationException;

/**
 * The two rules every translatable edit screen follows.
 *
 * Written for readings, and true of cards for the same reasons, so they live
 * here rather than in a second copy: nothing is required field by field, and
 * copy that exists in the original language cannot be deleted.
 */
trait EditsTranslatableContent
{
    /**
     * Tells her what this record is still waiting for, rather than refusing a
     * save.
     *
     * Nothing is required field by field. Writing is incremental, and a field
     * level rule also fires while switching language tabs, which made looking
     * at an untranslated language and changing your mind impossible. A record
     * simply is not on the website until it is finished, so the panel says what
     * is missing instead of arguing about it.
     */
    public function getSubheading(): ?string
    {
        $record = $this->getRecord();
        $locale = $this->activeLocale ?? Locales::default();
        $missing = $record->whatIsMissing($locale);

        if ($missing === []) {
            return $locale === Locales::default()
                ? 'On the website.'
                : 'On the website in '.$this->localeName($locale).' too.';
        }

        $language = $locale === Locales::default() ? '' : ' in '.$this->localeName($locale);

        return 'Not on the website'.$language.' yet. It needs '
            .collect($missing)->join(', ', ' and ').'.';
    }

    /**
     * Copy that exists in the original language cannot be deleted.
     *
     * Everything else is written from the English, and a record with no English
     * name is one nothing can be built from. Translations are different:
     * emptying one is a legitimate act, and it takes that language off the
     * website until it is written again.
     *
     * Checked at save rather than as a `required` rule, for the reason above.
     */
    protected function beforeSave(): void
    {
        if (! $this->editingDefaultLocale()) {
            return;
        }

        $record = $this->getRecord();
        $errors = [];

        foreach ($record::requiredTranslations() as $field) {
            $existing = $record->getTranslation($field, Locales::default(), false);

            if (filled($existing) && blank($this->data[$field] ?? null)) {
                $errors["data.{$field}"] = 'This cannot be left empty once it has been written. '
                    .'Change the wording rather than removing it.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    protected function editingDefaultLocale(): bool
    {
        return ($this->activeLocale ?? Locales::default()) === Locales::default();
    }

    private function localeName(string $locale): string
    {
        return Locales::find($locale)?->english_name ?? $locale;
    }
}
