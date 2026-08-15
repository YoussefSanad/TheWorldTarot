<?php

namespace App\Filament\Resources\Orders;

use App\Filament\Resources\Orders\Pages\ListOrders;
use App\Filament\Resources\Orders\Tables\OrdersTable;
use App\Models\Order;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What people have bought, and what Jennifer still owes them.
 *
 * Read only, with two things she can do to a row: say the money arrived, and
 * say the reading has gone. Everything else about an order is a record of
 * something that happened, and a record you can edit is not a record.
 *
 * **Nothing here edits money.** Not the total, not a line, not a price. What
 * was charged is what was charged, and a panel that could change it would be a
 * panel that could disagree with the card statement.
 */
class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $navigationLabel = 'Orders';

    protected static ?int $navigationSort = 10;

    public static function table(Table $table): Table
    {
        return OrdersTable::configure($table);
    }

    /**
     * Orders arrive by somebody buying something. There is no other way for one
     * to exist, and a hand made order would be a purchase nobody paid for.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Never. An order is the record of a payment, and deleting one loses the
     * only account of money that changed hands.
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
     * The badge next to "Orders" in the sidebar: how many readings are waiting
     * to be written. It is the number she actually needs to see.
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = Order::awaitingFulfillment()->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
        ];
    }
}
