<?php

namespace App\Filament\Resources\Gifts;

use App\Filament\Resources\Gifts\Pages\ListGifts;
use App\Filament\Resources\Gifts\Tables\GiftsTable;
use App\Models\Gift;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What is owed to somebody who has not asked for it yet.
 *
 * **One screen per question**, `docs/adr/0006-one-screen-per-question.md`.
 * Orders answers what money came in and Readings answers what work is owed.
 * This one answers the third, which only became a separate question when
 * gifting arrived: what obligation is outstanding. A gift bought in September
 * and redeemed in December makes those three answers disagree for three months.
 *
 * **It exists because `docs/adr/0005-gift-codes-do-not-expire.md` refused
 * expiry.** That decision accepts an obligation that never lapses and names the
 * cost of accepting it: without a screen, a recipient whose address bounced is
 * invisible, because the money is taken, the code exists, and nobody is waiting
 * for anything they know about. This is the screen, and it ships in the first
 * release rather than after for that reason.
 *
 * **Read only, with one thing she can do to a row: send the mail again.**
 * Everything a gift knows was typed by the buyer at checkout, and a panel that
 * could rewrite it would be a panel that could disagree with what was bought.
 */
class GiftResource extends Resource
{
    protected static ?string $model = Gift::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $navigationLabel = 'Gifts';

    /**
     * Third, below Readings at 5 and Orders at 10. Those two are opened every
     * day and this is a screen somebody checks, so it goes underneath them.
     */
    protected static ?int $navigationSort = 15;

    /**
     * Only the presents that exist.
     *
     * **A gift with no code is not on this screen**, and that is the one
     * judgment this unit makes that no ADR made for it. A row is written when
     * the buyer types the four fields at checkout and the code is minted later,
     * when the money arrives, so between those two moments there is a row that
     * owes nobody anything: nothing was taken, nothing was sent, and nothing
     * can be redeemed. Counting an abandoned checkout as an outstanding
     * obligation would put the one number on a liability screen out by however
     * many people got as far as the payment page and stopped.
     *
     * **`code IS NOT NULL` rather than a clause about the order**, because it
     * is the same fact asked at first hand. `MarkOrderPaid` mints inside the
     * transaction that settles the order, so a minted gift is a paid one, and
     * asking the gift is one join shorter than asking the money.
     *
     * The eager loads are what the reading column needs. `withTrashed` on the
     * product relationship is `OrderLine`'s own, so a retired reading still has
     * a name here.
     *
     * @return Builder<Gift>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNotNull('code')
            ->with('orderLine.product');
    }

    public static function table(Table $table): Table
    {
        return GiftsTable::configure($table);
    }

    /**
     * **No hand-issued codes**, `docs/adr/0006-one-screen-per-question.md`. The
     * superseded `docs/plans/gifting-plan.md` wanted Jennifer able to issue one
     * as an apology; a hand-issued gift has no purchase behind it, and
     * `gifts.order_line_id` is not nullable. Building it means deciding whether
     * a gift can exist without money, which is a larger question than the one
     * it was reached for.
     */
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * **Not editable, and not because editing is hard.** Changing a gift's
     * recipient address is not what it looks like: the original mail is in an
     * inbox and the code in it still works, so "changing" the address hands a
     * second person a live bearer credential rather than moving one. That case
     * is revoke and reissue, which is a different feature with a different
     * name.
     */
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /**
     * Never. A gift is the record of a reading somebody paid for and nobody has
     * had yet, and deleting one discharges an obligation by forgetting it.
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
     * How many presents are still unclaimed.
     *
     * The liability, as one number. It counts what the screen opens on, so the
     * badge and the first thing she sees agree.
     */
    public static function getNavigationBadge(): ?string
    {
        $outstanding = Gift::outstanding()->count();

        return $outstanding > 0 ? (string) $outstanding : null;
    }

    /**
     * **Neutral, not warning**, and ADR 0006 says why in as many words. An
     * unredeemed gift is not a task. A badge that is permanently orange because
     * three gifts are legitimately outstanding teaches somebody to stop reading
     * badges, and the one it costs them is `ReadingResource`'s, which does mean
     * something.
     */
    public static function getNavigationBadgeColor(): ?string
    {
        return 'gray';
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGifts::route('/'),
        ];
    }
}
