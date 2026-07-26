<?php

namespace App\Filament\Resources\Products\Tables;

use App\Support\Money;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable(),

                // Visible at a glance, per .claude/filament.md. Whether
                // something is on sale is the thing most worth seeing without
                // opening a record.
                IconColumn::make('is_published')
                    ->label('On the website')
                    ->boolean(),

                ...self::priceColumns(),

                TextColumn::make('sort_order')
                    ->label('Position')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // No create, no delete, no bulk actions. Products are seeded, and
            // taking one off sale is unpublishing it.
            ->toolbarActions([]);
    }

    /**
     * @return array<int, TextColumn>
     */
    private static function priceColumns(): array
    {
        return collect(config('currencies.available'))
            ->map(fn (array $currency, string $code) => TextColumn::make('price_'.strtolower($code))
                ->label($currency['label'])
                ->formatStateUsing(fn (?int $state): string => $state === null
                    ? ''
                    : $currency['symbol'].Money::toMajor($state))
            )
            ->values()
            ->all();
    }
}
