<?php

namespace App\Filament\Resources\Readings;

use App\Filament\Resources\Readings\Pages\ListReadings;
use App\Filament\Resources\Readings\Pages\ViewReading;
use App\Filament\Resources\Readings\Schemas\ReadingInfolist;
use App\Filament\Resources\Readings\Tables\ReadingsTable;
use App\Models\Reading;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What work is owed, and what has gone.
 *
 * **One screen per question**, `docs/adr/0006-one-screen-per-question.md`.
 * `OrderResource` answered three at once, and until gifting they were the same
 * question asked three ways: what money came in, what work is owed, what is
 * outstanding. A gift bought in September and redeemed in December makes those
 * three answers disagree for three months, so this screen took the second one
 * and Orders kept the first.
 *
 * **A row is an asking**, which is why it is readings and not orders. She
 * delivers a reading, not an order, and an order holding two of them is not
 * finished by the first going out.
 *
 * **The first resource in this panel with a detail page.** A question runs to
 * 2000 characters and a gift adds a signature and the buyer's message on top of
 * it, which stopped fitting a wrapped table cell.
 */
class ReadingResource extends Resource
{
    protected static ?string $model = Reading::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPencilSquare;

    protected static ?string $navigationLabel = 'Readings';

    /**
     * Above Orders, because this is the screen she works from and that one is a
     * record she consults.
     */
    protected static ?int $navigationSort = 5;

    public static function table(Table $table): Table
    {
        return ReadingsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ReadingInfolist::configure($schema);
    }

    /**
     * A reading arrives by somebody asking for one, at settlement or at
     * redemption. There is no other way for one to exist, and a hand made
     * reading would be work nobody bought.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * Nor edited. What somebody asked is what they asked, and a question a
     * panel can rewrite is not the question the reading answers.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Never. Deleting one loses the only record that somebody is waiting, and
     * the money for it has already been taken.
     */
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    /**
     * How many readings are still waiting to be written.
     *
     * **The badge moved here from Orders**, because readings waiting is the
     * number she acts on and orders paid is not. An order paid three months ago
     * whose gift nobody has redeemed is outstanding, and it is not work.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Reading::stillToWrite()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReadings::route('/'),
            'view' => ViewReading::route('/{record}'),
        ];
    }
}
