<?php

namespace App\Filament\Resources\Cards\Pages;

use App\Filament\Concerns\EditsTranslatableContent;
use App\Filament\Resources\Cards\CardResource;
use App\Support\Locales;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use LaraZeus\SpatieTranslatable\Actions\LocaleSwitcher;
use LaraZeus\SpatieTranslatable\Resources\Pages\EditRecord\Concerns\Translatable;

class EditCard extends EditRecord
{
    // Aliased rather than overridden, so the shared line about the website is
    // kept and the Viewing Room's is added to it.
    use EditsTranslatableContent {
        getSubheading as websiteStatus;
    }
    use Translatable;

    protected static string $resource = CardResource::class;

    /**
     * Two different questions a card can be unfinished for.
     *
     * Being on the website is about copy and a picture. Being in the Viewing
     * Room is about a video and a poster. A card is routinely one and not the
     * other, and saying only the first would leave her wondering why a finished
     * card is not watchable.
     */
    public function getSubheading(): ?string
    {
        $website = $this->websiteStatus();

        if (! $this->editingDefaultLocale()) {
            return $website;
        }

        $card = $this->getRecord();
        $waiting = $card->whatTheViewingRoomIsWaitingFor(Locales::default());

        if ($waiting === []) {
            return $website.' In the Viewing Room too.';
        }

        return $website.' Not in the Viewing Room yet: it needs '
            .collect($waiting)->join(', ', ' and ').'.';
    }

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
