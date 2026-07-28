<?php

namespace App\Filament\Resources\Cards;

use App\Filament\Resources\Cards\Pages\CreateCard;
use App\Filament\Resources\Cards\Pages\EditCard;
use App\Filament\Resources\Cards\Pages\ListCards;
use App\Filament\Resources\Cards\Schemas\CardForm;
use App\Filament\Resources\Cards\Tables\CardsTable;
use App\Models\Card;
use App\Support\Locales;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use LaraZeus\SpatieTranslatable\Resources\Concerns\Translatable;

/**
 * The one resource Jennifer creates rows in.
 *
 * Everything else here is seeded and edited, but she has a binding FAQ
 * commitment to people who already paid for the pass that she can add future
 * cards herself, without a developer. See .claude/filament.md.
 */
class CardResource extends Resource
{
    use Translatable;

    protected static ?string $model = Card::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $navigationLabel = 'Tarot cards';

    protected static ?string $modelLabel = 'card';

    /**
     * The language tabs, read when the form is built rather than when the panel
     * is registered.
     *
     * The plugin takes its list once, at registration, which is stale the
     * moment a language is added or removed.
     *
     * @return array<int, string>
     */
    public static function getTranslatableLocales(): array
    {
        return Locales::codes();
    }

    public static function form(Schema $schema): Schema
    {
        return CardForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CardsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCards::route('/'),
            'create' => CreateCard::route('/create'),
            'edit' => EditCard::route('/{record}/edit'),
        ];
    }
}
