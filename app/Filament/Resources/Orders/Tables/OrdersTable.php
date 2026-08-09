<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Actions\MarkOrderPaid;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderLine;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class OrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Newest first. The thing she opens this screen to find is whatever
            // just came in.
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('id')
                    ->label('Order')
                    ->formatStateUsing(fn (int $state): string => '#'.$state)
                    ->sortable(),

                TextColumn::make('customer.name')
                    ->label('From')
                    ->description(fn (Order $record): string => $record->customer->email)
                    ->searchable(),

                TextColumn::make('lines')
                    ->label('Bought')
                    ->state(fn (Order $record): string => $record->lines
                        ->map(fn (OrderLine $line): string => $line->quantity > 1
                            ? $line->quantity.' x '.self::nameOf($line)
                            : self::nameOf($line))
                        ->join(', ')),

                // Wrapped rather than truncated. This is the thing she cannot
                // write the reading without, so making her open something else
                // to read it would be the wrong trade.
                TextColumn::make('question')
                    ->label('What they asked')
                    ->state(fn (Order $record): ?string => $record->lines
                        ->pluck('question')
                        ->filter()
                        ->join("\n\n") ?: null)
                    ->placeholder('Nothing asked')
                    ->wrap(),

                TextColumn::make('total_amount')
                    ->label('Paid')
                    ->formatStateUsing(fn (int $state, Order $record): string => $record->currency.' '.Money::toMajor($state))
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->label())
                    ->color(fn (OrderStatus $state): string => $state->colour()),

                TextColumn::make('fulfilled_at')
                    ->label('Fulfilled')
                    ->dateTime('j M Y')
                    ->placeholder('Not yet')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Placed')
                    ->dateTime('j M Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(OrderStatus::cases())
                        ->mapWithKeys(fn (OrderStatus $status): array => [$status->value => $status->label()])
                        ->all()),

                TernaryFilter::make('fulfilled_at')
                    ->label('Fulfilled')
                    ->placeholder('All orders')
                    ->trueLabel('Already fulfilled')
                    ->falseLabel('Still to fulfill')
                    ->nullable(),
            ])
            ->recordActions([
                self::markAsPaid(),
                self::markAsFulfilled(),
            ])
            // No create, no delete, no bulk anything. An order is a record of
            // something that happened.
            ->toolbarActions([]);
    }

    /**
     * Saying the money arrived, by hand.
     *
     * Everything a card payment will do runs from here too, so an order settled
     * in this panel and one settled by a payment cannot come to mean different
     * things. It grants a pass, emails the customer, and tells Jennifer if
     * there is a reading to write.
     *
     * Confirmed, because it is not reversible from this screen and because a
     * customer hears about it immediately.
     */
    private static function markAsPaid(): Action
    {
        return Action::make('markAsPaid')
            ->label('Mark as paid')
            ->icon(Heroicon::OutlinedBanknotes)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Mark this order as paid?')
            ->modalDescription('The customer is emailed straight away, and anything they bought is unlocked for them. This cannot be undone here.')
            ->modalSubmitActionLabel('Yes, it has been paid')
            ->visible(fn (Order $record): bool => $record->status === OrderStatus::Pending)
            ->action(function (Order $record): void {
                $changed = app(MarkOrderPaid::class)($record);

                Notification::make()
                    ->success()
                    ->title($changed ? 'Marked as paid' : 'That order was already paid')
                    ->send();
            });
    }

    /**
     * Saying the reading has gone.
     *
     * The system notifies and records, it does not fulfill. She writes the
     * reading and emails the PDF herself, and this is how she keeps track of
     * which ones she has done.
     *
     * "Fulfilled" rather than "sent", because a pass is fulfilled by access
     * being granted and nothing about it is ever sent. One word has to cover
     * both, and only this one is true of both.
     */
    private static function markAsFulfilled(): Action
    {
        return Action::make('markAsFulfilled')
            ->label('Mark as fulfilled')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->requiresConfirmation()
            ->modalHeading('Mark this order as fulfilled?')
            ->modalDescription('Use this once you have emailed the reading, so it drops off your list.')
            ->modalSubmitActionLabel('Yes, it is done')
            ->visible(fn (Order $record): bool => $record->isPaid() && ! $record->isFulfilled())
            ->action(function (Order $record): void {
                $record->forceFill(['fulfilled_at' => now()])->save();

                Notification::make()->success()->title('Marked as fulfilled')->send();
            });
    }

    /**
     * What the customer sees this product called, falling back to the key it
     * was bought under if the row has since been retired.
     */
    private static function nameOf(OrderLine $line): string
    {
        return $line->product?->name ?: $line->product_key->value;
    }
}
