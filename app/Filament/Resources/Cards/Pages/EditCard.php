<?php

namespace App\Filament\Resources\Cards\Pages;

use App\Filament\Concerns\EditsTranslatableContent;
use App\Filament\Resources\Cards\CardResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use LaraZeus\SpatieTranslatable\Actions\LocaleSwitcher;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;

class EditCard extends EditRecord
{
    use EditsTranslatableContent, Translatable;

    protected static string $resource = CardResource::class;

    protected function getHeaderActions(): array
    {
        return [
            LocaleSwitcher::make(),

            // Cards are hers to add, so they have to be hers to remove. Soft
            // deleted, because M4 attaches a video to the row.
            DeleteAction::make()
                ->label('Remove')
                ->modalHeading('Remove this card')
                ->modalDescription('It comes off the website, and its writing goes with it. Ask a developer if you need it back.'),
        ];
    }
}
