<?php

namespace App\Filament\Resources\Cards\Schemas;

use App\Support\Locales;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

/**
 * Deliberately absent: any web address field. A card is a pop-up opened from
 * the Library rather than a page of its own, so there is no link to keep stable
 * and nothing here she cannot change her mind about later.
 */
class CardForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('The card')
                    ->description('The language tab at the top decides which language you are editing.')
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->helperText('Shown on the card and in the Library.')
                            ->maxLength(255)
                            // Only when the card is first added, and only in
                            // the original language. A card with no name at all
                            // is one nothing else can refer to. Never required
                            // on a translation, since writing is incremental.
                            ->required(fn (string $operation): bool => $operation === 'create')
                            ->validationMessages([
                                'required' => 'Give the card a name before adding it.',
                            ]),

                        Textarea::make('short_description')
                            ->label('Short description')
                            ->helperText('One or two lines, shown when the card opens.')
                            ->rows(3),

                        Textarea::make('long_description')
                            ->label('Full description')
                            ->helperText('The longer meaning, below the short one.')
                            ->rows(10),
                    ]),

                Section::make('The picture')
                    ->description('The same picture in every language, so it is only uploaded once.')
                    // Shown on the original language only. The picture belongs
                    // to the card rather than to a language, and a file field
                    // sitting on a translation tab is emptied by the language
                    // switcher, which would delete the picture on the next save.
                    ->visible(fn (mixed $livewire): bool => self::onOriginalLanguage($livewire))
                    ->schema([
                        FileUpload::make('image_path')
                            ->label('Card picture')
                            ->helperText('The card as it appears in the Library. Until this is here, the card is not on the website.')
                            ->image()
                            ->imageEditor()
                            ->disk(config('filesystems.media'))
                            ->directory('cards')
                            ->visibility('public')
                            ->maxSize(10240)
                            // Belt and braces: never written from a translation
                            // tab, whatever the form happens to be showing.
                            ->dehydrated(fn (mixed $livewire): bool => self::onOriginalLanguage($livewire))
                            ->validationMessages([
                                'max' => 'That picture is too large. Keep it under 10MB.',
                            ]),
                    ]),

                Section::make('Where it sits in the deck')
                    ->schema([
                        TextInput::make('number')
                            ->label('Card number')
                            ->helperText('The number on the card. The Fool is 0, and cards are ordered by it.')
                            ->numeric()
                            ->minValue(0)
                            ->required()
                            // Deleted cards do not hold their number. Without
                            // this, removing a card and adding it back is
                            // refused by the panel even though the database
                            // allows it.
                            ->unique(
                                ignoreRecord: true,
                                modifyRuleUsing: fn (Unique $rule): Unique => $rule->whereNull('deleted_at'),
                            )
                            ->validationMessages([
                                'unique' => 'There is already a card with this number.',
                            ]),
                    ]),
            ]);
    }

    /**
     * Whether the screen is showing the language everything is written from.
     *
     * True while adding a card, which has no language tabs at all.
     */
    private static function onOriginalLanguage(mixed $livewire): bool
    {
        return ($livewire->activeLocale ?? Locales::default()) === Locales::default();
    }
}
