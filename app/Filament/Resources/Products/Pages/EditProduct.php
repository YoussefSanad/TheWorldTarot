<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Support\Locales;
use Filament\Resources\Pages\EditRecord;
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

    private function localeName(string $locale): string
    {
        return Locales::find($locale)?->english_name ?? $locale;
    }
}
