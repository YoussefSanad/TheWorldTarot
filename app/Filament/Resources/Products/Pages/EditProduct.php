<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Concerns\EditsTranslatableContent;
use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\EditRecord;
use LaraZeus\SpatieTranslatable\Actions\LocaleSwitcher;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;

class EditProduct extends EditRecord
{
    use EditsTranslatableContent, Translatable;

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
}
