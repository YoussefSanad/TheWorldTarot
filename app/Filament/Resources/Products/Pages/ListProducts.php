<?php

namespace App\Filament\Resources\Products\Pages;

use App\Filament\Concerns\ShowsTranslationProgress;
use App\Filament\Resources\Products\ProductResource;
use Filament\Resources\Pages\ListRecords;
use LaraZeus\SpatieTranslatable\Actions\LocaleSwitcher;
use LaraZeus\SpatieTranslatable\Resources\Pages\ListRecords\Concerns\Translatable;

class ListProducts extends ListRecords
{
    use ShowsTranslationProgress, Translatable;

    protected static string $resource = ProductResource::class;

    /**
     * The language switcher only. There is deliberately no create action.
     */
    protected function getHeaderActions(): array
    {
        return [
            LocaleSwitcher::make(),
        ];
    }
}
