<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Models\Product;
use App\Support\Locales;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use LaraZeus\SpatieTranslatable\Actions\LocaleSwitcher;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;

class EditProduct extends EditRecord
{
    use Translatable;

    protected static string $resource = ProductResource::class;

    /**
     * The language switcher only. No delete, no force delete, no restore:
     * products are seeded, and nothing here is ever retired.
     */
    protected function getHeaderActions(): array
    {
        return [
            LocaleSwitcher::make(),
        ];
    }

    /**
     * Tells her what this reading is still waiting for, in her language, rather
     * than refusing a save.
     *
     * Nothing is required field by field. Writing is incremental, and a field
     * level rule also fires while switching language tabs, which made looking
     * at an untranslated language and changing your mind impossible. A reading
     * simply is not on the website until it is finished, so the panel says what
     * is missing instead of arguing about it.
     */
    public function getSubheading(): ?string
    {
        $product = $this->getRecord();
        $locale = $this->activeLocale ?? Locales::default();
        $missing = $product->whatIsMissing($locale);

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
     * Everything else is written from the English, and a reading with no
     * English name is a reading nothing can be built from. Translations are
     * different: emptying one is a legitimate act, and it takes that language
     * off the website until it is written again.
     *
     * Checked at save rather than as a `required` rule, because a field level
     * rule also fires while switching language tabs and makes looking at an
     * untranslated language impossible.
     */
    protected function beforeSave(): void
    {
        if (! ProductForm::editingDefaultLocale($this)) {
            return;
        }

        $product = $this->getRecord();
        $errors = [];

        foreach (Product::requiredTranslations() as $field) {
            $existing = $product->getTranslation($field, Locales::default(), false);

            if (filled($existing) && blank($this->data[$field] ?? null)) {
                $errors["data.{$field}"] = 'This cannot be left empty once it has been written. '
                    .'Change the wording rather than removing it.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function localeName(string $locale): string
    {
        return Locales::find($locale)?->english_name ?? $locale;
    }
}
