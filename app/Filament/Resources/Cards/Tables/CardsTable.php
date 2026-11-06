<?php

namespace App\Filament\Resources\Cards\Tables;

use App\Enums\VideoStatus;
use App\Filament\Resources\Cards\Schemas\CardForm;
use App\Models\Card;
use App\Models\Locale;
use App\Support\Locales;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('number')
            ->columns([
                ImageColumn::make('image_path')
                    ->label('Picture')
                    ->disk(config('filesystems.media'))
                    ->placeholder('None yet'),

                TextColumn::make('number')
                    ->label('Number')
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Name')
                    ->searchable(),

                // Worked out from the card rather than switched on, so there is
                // nothing to remember to tick and nothing left in the wrong
                // position by accident.
                IconColumn::make('available')
                    ->label('On the website')
                    ->boolean()
                    ->state(fn (Card $record): bool => $record->isAvailableIn(Locales::default())),

                // Status has to be obvious without opening a record, per
                // .claude/filament.md. A card being prepared and a card that
                // failed look identical if nobody says which is which.
                TextColumn::make('video_status')
                    ->label('Video')
                    ->badge()
                    ->placeholder('None yet')
                    ->formatStateUsing(fn (VideoStatus $state): string => $state->label())
                    ->color(fn (VideoStatus $state): string => $state->colour()),

                TextColumn::make('translations')
                    ->label('Translated into')
                    ->badge()
                    ->color('success')
                    ->placeholder('English only')
                    ->state(fn (Card $record): array => Locales::all()
                        ->reject(fn (Locale $locale): bool => $locale->code === Locales::default())
                        ->filter(fn (Locale $locale): bool => $record->isCompleteIn($locale->code))
                        ->pluck('english_name')
                        ->all()),
            ])
            // Only while a film is on its way, so the badge moves from waiting
            // to being prepared to ready without her refreshing, and an idle
            // list is not asking the server anything.
            ->poll(fn (): ?string => Card::query()
                ->whereIn('video_status', [VideoStatus::Pending, VideoStatus::Processing])
                ->exists() ? '10s' : null)
            ->recordActions([
                self::videoAction(),
                EditAction::make(),
                self::deleteAction(),
            ])
            // No bulk actions. Deleting a deck by accident is not a mistake
            // anyone should be able to make in one click.
            ->toolbarActions([]);
    }

    /**
     * Loading a card's film straight from the list, without the round trip
     * through the edit page.
     *
     * Saving the path is the whole job. The card's own hooks mark it waiting,
     * queue it for Bunny and throw the old master away, exactly as a save from
     * the edit page does, so there is one pipeline rather than two. Nothing
     * here waits on Bunny: the modal closes once the file is uploaded, and the
     * next card can be started while this one is prepared.
     *
     * Deliberately never offered: removing a film. That stays on the edit page,
     * where she is told it is deleted for good.
     */
    private static function videoAction(): Action
    {
        return Action::make('uploadVideo')
            ->label(fn (Card $record): string => $record->hasVideo() ? 'Replace video' : 'Add video')
            ->icon(Heroicon::OutlinedFilm)
            ->modalHeading(fn (Card $record): string => ($record->hasVideo() ? 'Replace the video for ' : 'Add a video to ').$record->name)
            ->modalDescription(fn (Card $record): string => self::videoModalDescription($record))
            ->modalSubmitActionLabel('Upload')
            // A stray click beside the modal would throw away an upload that
            // may have taken minutes. Cancel and the X still close it.
            ->closeModalByClickingAway(false)
            ->schema([CardForm::videoUpload()->required()])
            ->action(function (Card $record, array $data): void {
                $record->update(['video_master_path' => $data['video_master_path']]);

                Notification::make()
                    ->success()
                    ->title("{$record->name}'s video is uploaded")
                    ->body('It is being prepared now. You can carry on with the next card.')
                    ->send();
            })
            // Removed cards are not listed, but a removed card is never given
            // a new film whichever way the action is reached.
            ->hidden(fn (Card $record): bool => $record->trashed());
    }

    /**
     * What happens to what customers see, in plain words.
     *
     * The film already playing stays playing until the new one is ready, and
     * only a card that has one should be told so.
     */
    private static function videoModalDescription(Card $record): string
    {
        $timing = 'MP4 or QuickTime, up to 200MB. It takes a few minutes to be prepared after uploading.';

        if ($record->isPlayable()) {
            return "{$timing} The film customers see now keeps playing until the new one is ready, then the new one takes its place. The file you are replacing is deleted, so keep your own copy.";
        }

        return "{$timing} The card goes into the Viewing Room once it is ready.";
    }

    /**
     * Removing a card she added by mistake.
     *
     * The row is kept, because M4 attaches a video to it and a hard delete
     * would orphan the file. What she is told is what she needs to know: it
     * comes off the website and the writing goes with it.
     */
    private static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()
            ->label('Remove')
            ->modalHeading('Remove this card')
            ->modalDescription('It comes off the website, and its writing goes with it. Ask a developer if you need it back.');
    }
}
