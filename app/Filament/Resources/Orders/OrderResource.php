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
 * **Money, and only money, since 3 September 2026.** One screen per question,
 * `docs/adr/0006-one-screen-per-question.md`: this one answers what came in and
 * a row is a purchase. What work is owed moved to `ReadingResource`, and with
 * it the question column, `Mark as fulfilled` and the navigation badge.
 *
 * ~~Read only, with one thing she can do to a row: say the money arrived.~~
 * **Struck 22 October 2026: read only, with no row actions.** Stripe is the
 * only road to paid.
 * Everything else about an order is a record of something that happened, and a
 * record you can edit is not a record.
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
     * **No badge, since 3 September 2026 and ADR 0006.** It counted
     * `awaitingFulfillment` and moved to `ReadingResource`, because readings
     * waiting is the number she acts on and orders paid is not. An order paid
     * three months ago whose gift nobody has redeemed is outstanding, and it is
     * not work.
     */
    public static function getPages(): array
    {
        return [
            'index' => ListOrders::route('/'),
        ];
    }
}
