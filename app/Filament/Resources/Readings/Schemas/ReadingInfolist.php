<?php

namespace App\Filament\Resources\Readings\Schemas;

use App\Models\Reading;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Everything she needs to write one reading, on one page.
 *
 * **The first detail page in this panel.** The list is for scanning and this is
 * for working from, which is why the question is whole here and a first line
 * there.
 *
 * Deliberately absent: money, the order's status, and anything about the
 * payment. Orders answers that, and no screen here answers two questions.
 */
class ReadingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('What they asked')
                    ->schema([
                        // Whole, and never truncated. This is the thing she
                        // cannot write the reading without.
                        TextEntry::make('question')
                            ->hiddenLabel()
                            ->placeholder('No question was asked.'),
                    ]),

                Section::make('Who it goes to')
                    ->schema([
                        TextEntry::make('querent_email')
                            ->label('Email'),

                        TextEntry::make('querent_name')
                            ->label('Name')
                            ->placeholder('Not given'),

                        TextEntry::make('orderLine.product_key')
                            ->label('Reading')
                            ->formatStateUsing(fn (Reading $record): string => $record->orderLine->product?->name
                                ?: $record->orderLine->product_key->value),
                    ])
                    ->columns(2),

                // **Only on a gift**, because a purchase has neither of these
                // and an empty section would be two blanks on every other row.
                //
                // The signature is who it is from and is not a name: it is what
                // the recipient's mail was signed with, so "Mum" is a truer
                // answer than what is on the card. The message is the buyer's
                // own words, and she is writing for somebody who has never
                // heard from us before.
                Section::make('A gift')
                    ->visible(fn (Reading $record): bool => $record->isAGift())
                    ->schema([
                        TextEntry::make('gift.signature')
                            ->label('From'),

                        TextEntry::make('gift.message')
                            ->label('Their message')
                            ->placeholder('No message was left'),
                    ])
                    ->columns(2),

                Section::make('When')
                    ->schema([
                        TextEntry::make('asked_at')
                            ->label('Asked')
                            ->dateTime('j M Y, H:i'),

                        // Computed, never stored. The same value the list
                        // colours and sorts by.
                        TextEntry::make('due')
                            ->label('Due')
                            ->state(fn (Reading $record): string => $record->dueAt()?->format('j M Y, H:i') ?? 'No window')
                            ->color(fn (Reading $record): string => $record->isOverdue() ? 'danger' : 'gray'),

                        TextEntry::make('delivered_at')
                            ->label('Delivered')
                            ->dateTime('j M Y, H:i')
                            ->placeholder('Not yet'),
                    ])
                    ->columns(3),
            ]);
    }
}
