<?php

namespace App\Filament\Resources\Cards\Pages;

use App\Filament\Resources\Cards\CardResource;
use Filament\Resources\Pages\CreateRecord;
use LaraZeus\SpatieTranslatable\Resources\Pages\CreateRecord\Concerns\Translatable;

class CreateCard extends CreateRecord
{
    use Translatable;

    protected static string $resource = CardResource::class;

    /**
     * No language switcher here, deliberately.
     *
     * A card is added in the original language and translated afterwards, on
     * the edit screen where the tabs live. Offering the tabs before the card
     * exists invites starting in Spanish, which would leave a card the site
     * cannot show in any language and the language gate immediately
     * complaining about.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Card added. Write its description next, and it goes on the website.';
    }
}
