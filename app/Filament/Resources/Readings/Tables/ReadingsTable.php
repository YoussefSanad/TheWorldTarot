<?php

namespace App\Filament\Resources\Readings\Tables;

use App\Actions\DeliverReading;
use App\Models\Reading;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ReadingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // **Oldest ask first, which inverts Orders deliberately.** Orders
            // is newest first because the thing she opens it to find is
            // whatever just came in. This screen answers the opposite question,
            // what has been kept waiting longest, and sorting it the same way
            // would bury exactly the row that matters.
            ->defaultSort('asked_at', 'asc')
            ->columns([
                // **Computed at read time and never stored.** A `due_at` column
                // is a second writer for a value that already has one and goes
                // stale the day a window changes. Sorting is the one part that
                // cannot be done in PHP, since it has to order rows the
                // database has not sent yet, and `Reading::scopeOrderByDue`
                // builds that from the same catalogue this reads.
                //
                // **The words and the colour come from `Due`, not from here.**
                // This screen used to name the states itself, and knew three of
                // the four while the page it opens knew two, which is the
                // disagreement the enum was added to end. What is left here is
                // the rendering, which is this screen's own.
                TextColumn::make('due')
                    ->label('Due')
                    ->state(fn (Reading $record): string => $record->due()->words($record->dueAt()))
                    ->badge()
                    ->color(fn (Reading $record): string => $record->due()->colour())
                    ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByDue($direction)),

                TextColumn::make('orderLine.product_key')
                    ->label('Reading')
                    ->formatStateUsing(fn (Reading $record): string => self::nameOf($record)),

                // **Captured, not joined.** A gift's querent may have no
                // customers row at all and deliberately never gets one, so the
                // address on the reading is the only one there is.
                TextColumn::make('querent_email')
                    ->label('Querent')
                    ->description(fn (Reading $record): ?string => $record->querent_name)
                    ->searchable(),

                // The querent of a gift never chose the reading they are
                // holding and has never heard from us before. She is writing
                // for a stranger, and that is worth knowing at a glance.
                TextColumn::make('gift_id')
                    ->label('From')
                    ->badge()
                    ->color('info')
                    ->state(fn (Reading $record): ?string => $record->isAGift() ? 'Gift' : null)
                    ->placeholder('Bought'),

                // **The first line only.** The whole of it is on the detail
                // page, which exists because 2000 characters stopped fitting a
                // wrapped table cell.
                TextColumn::make('question')
                    ->label('What they asked')
                    ->state(fn (Reading $record): ?string => self::firstLineOf($record))
                    ->placeholder('Nothing asked')
                    ->wrap(),

                TextColumn::make('asked_at')
                    ->label('Asked')
                    ->dateTime('j M Y')
                    ->sortable(),

                TextColumn::make('delivered_at')
                    ->label('Delivered')
                    ->dateTime('j M Y')
                    ->placeholder('Not yet')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('delivered_at')
                    ->label('Delivered')
                    ->placeholder('All readings')
                    ->trueLabel('Already delivered')
                    ->falseLabel('Still to write')
                    ->nullable(),
            ])
            ->recordActions([
                self::markDelivered(),
            ])
            // No create, no delete, no bulk anything. A reading is the record
            // that somebody is waiting.
            ->toolbarActions([]);
    }

    /**
     * Saying the reading has gone.
     *
     * **Built as the action a PDF upload later hangs off, not as a button an
     * upload replaces.** ADR 0006 refuses the upload for this epic and says
     * why; the shape of the action is the part that has to survive it. Nothing
     * here stamps a date: `DeliverReading` owns that, and the upload will reach
     * the same action once it stores the file and sends the mail.
     *
     * **Public because the detail page offers it too.** One declaration, so the
     * two cannot come to disagree about when she is offered it.
     */
    public static function markDelivered(): Action
    {
        return Action::make('markDelivered')
            ->label('Mark delivered')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->requiresConfirmation()
            ->modalHeading('Mark this reading as delivered?')
            ->modalDescription('Use this once you have emailed the reading, so it drops off your list. The order it belongs to is finished off for you once everything on it has gone.')
            ->modalSubmitActionLabel('Yes, it has gone')
            ->visible(fn (Reading $record): bool => ! $record->isDelivered())
            ->action(function (Reading $record): void {
                $changed = app(DeliverReading::class)($record);

                Notification::make()
                    ->success()
                    ->title($changed ? 'Marked delivered' : 'That reading had already gone')
                    ->send();
            });
    }

    /**
     * What the customer sees this reading called, falling back to the key it
     * was bought under if the row has since been retired.
     */
    private static function nameOf(Reading $record): string
    {
        $line = $record->orderLine;

        return $line->product?->name ?: $line->product_key->value;
    }

    private static function firstLineOf(Reading $record): ?string
    {
        return blank($record->question) ? null : Str::before(trim($record->question), "\n");
    }
}
