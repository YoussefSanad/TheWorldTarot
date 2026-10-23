<?php

namespace App\Filament\Resources\Orders\Tables;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderLine;
use App\Support\Money;
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

                // **An order can be from nobody**, and that reads as
                // "Not given yet" rather than as an empty cell that looks like
                // a bug. Stripe's page collects the buyer's email after the
                // order exists, so a pending order placed there holds no
                // identity until it settles, and an abandoned one never does.
                //
                // **Two shapes reach the placeholder since issue #52**, and the
                // second is the commoner one. A settled order has a customer,
                // and that customer may have no name: nothing on the road they
                // took asked for one, and a wallet is asked for an address
                // rather than a billing contact. The description below is what
                // she goes on in both cases, since the address is the half that
                // always arrives.
                TextColumn::make('customer.name')
                    ->label('From')
                    ->placeholder('Not given yet')
                    ->description(fn (Order $record): ?string => $record->customer?->email)
                    ->searchable(),

                TextColumn::make('lines')
                    ->label('Bought')
                    ->state(fn (Order $record): string => $record->lines
                        ->map(fn (OrderLine $line): string => $line->quantity > 1
                            ? $line->quantity.' x '.$line->displayName()
                            : $line->displayName())
                        ->join(', ')),

                // **`What they asked` left on 3 September 2026**, for
                // `ReadingsResource` and a detail page of its own, ADR 0006.
                // This screen is money and a row is a purchase; a gift bought
                // in September and redeemed in December makes "what money came
                // in" and "what work is owed" two answers about one row, and
                // one column cannot give both.

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
            // **`Mark as fulfilled` left with the question column.** She
            // delivers a reading and not an order, so the action stamps
            // `readings.delivered_at` on the readings screen and an order
            // becoming fulfilled follows from everything it owes having gone.
            // `fulfilled_at` stays a column and stops being something anybody
            // sets by hand.
            //
            // **Marking an order paid left on 22 October 2026.** ~~An order could be settled
            // here by hand.~~ Stripe is the only road to paid, so there is
            // no record action at all.
            ->recordActions([])
            // No create, no delete, no bulk anything. An order is a record of
            // something that happened.
            ->toolbarActions([]);
    }
}
