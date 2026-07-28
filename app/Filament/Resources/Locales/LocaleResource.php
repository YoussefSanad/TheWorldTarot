<?php

namespace App\Filament\Resources\Locales;

use App\Filament\Resources\Locales\Pages\ListLocales;
use App\Filament\Resources\Locales\Tables\LocalesTable;
use App\Models\Locale;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The languages screen.
 *
 * Deliberately not a CRUD resource. Languages are added by a developer,
 * because a language needs its wording shipped in the website's own code
 * before it can mean anything. What this screen is for is the two things that
 * must not need a developer: seeing how far a translation has got, and putting
 * a language in front of customers or pulling it back.
 */
class LocaleResource extends Resource
{
    protected static ?string $model = Locale::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static ?string $navigationLabel = 'Languages';

    protected static ?string $recordTitleAttribute = 'english_name';

    public static function table(Table $table): Table
    {
        return LocalesTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLocales::route('/'),
        ];
    }
}
