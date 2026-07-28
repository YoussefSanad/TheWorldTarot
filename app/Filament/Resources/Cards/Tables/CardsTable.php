<?php

namespace App\Filament\Resources\Cards\Tables;

use App\Models\Card;
use App\Models\Locale;
use App\Support\Locales;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class CardsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('number')
            ->columns([
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
            ->recordActions([
                EditAction::make(),
                self::deleteAction(),
            ])
            // No bulk actions. Deleting a deck by accident is not a mistake
            // anyone should be able to make in one click.
            ->toolbarActions([]);
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
