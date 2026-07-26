<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Resources\Products\ProductResource;
use App\Filament\Resources\Products\Schemas\ProductForm;
use App\Support\Money;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;
use LaraZeus\SpatieTranslatable\Actions\LocaleSwitcher;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;

class EditProduct extends EditRecord
{
    use Translatable;

    protected static string $resource = ProductResource::class;

    /**
     * The copy a reading cannot go on sale without.
     */
    private const REQUIRED_TO_PUBLISH = [
        'name' => 'Add a name before showing this on the website.',
        'short_description' => 'Add a short description before showing this on the website.',
        'long_description' => 'Add the full description before showing this on the website.',
        'slug' => 'Add a web address before showing this on the website.',
    ];

    /**
     * The language switcher only. No delete, no force delete, no restore:
     * products are seeded, and taking one off sale means unpublishing it.
     */
    protected function getHeaderActions(): array
    {
        return [
            LocaleSwitcher::make(),
        ];
    }

    /**
     * Everything a reading needs before it can be published, checked in one
     * place at the moment of saving.
     *
     * Deliberately not field level `required` rules. Those fire while switching
     * language tabs, because the switcher validates the tab it is leaving
     * against the language it is arriving at, which made it impossible to look
     * at an untranslated language and change your mind. They also block saving
     * a draft, and writing is incremental.
     */
    protected function beforeSave(): void
    {
        if (! ($this->data['is_published'] ?? false)) {
            return;
        }

        $errors = [];

        // Copy is per language, and publishing is only offered on the default
        // language tab, so this only ever inspects the default language's
        // values. A translation being incomplete never blocks a sale.
        if (ProductForm::editingDefaultLocale($this)) {
            foreach (self::REQUIRED_TO_PUBLISH as $field => $message) {
                if (blank($this->data[$field] ?? null)) {
                    $errors["data.{$field}"] = $message;
                }
            }
        }

        // Prices are not translatable, so they are checked whatever tab is
        // open. A seeded reading starts at zero in every currency, which the
        // database happily accepts: `NOT NULL` can insist a price exists, not
        // that it means anything.
        foreach (array_keys(config('currencies.available')) as $code) {
            $field = 'price_'.strtolower($code);

            if ($this->priceIsUnset($this->data[$field] ?? null)) {
                $errors["data.{$field}"] = 'Set a price above zero before showing this on the website.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function priceIsUnset(mixed $value): bool
    {
        try {
            // Anything that is not an amount at all is reported by the field's
            // own rule, so it is not this check's business.
            return Money::toMinor((string) $value) < 1;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
