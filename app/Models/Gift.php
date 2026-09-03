<?php

namespace App\Models;

use Database\Factories\GiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A reading bought for somebody who has not asked for it yet.
 *
 * **A row here is a liability and not a purchase.** The purchase is the order
 * line it hangs off, which was paid for on the day it was bought;
 * `docs/adr/0005-gift-codes-do-not-expire.md` accepts that what is owed in
 * return never lapses, so `redeemed_at IS NULL` is an obligation still on the
 * books rather than a state waiting to be tidied.
 *
 * **It exists from placement and its code does not.** The buyer types four
 * things at checkout and they have to survive until the money arrives, the way
 * `order_lines.question` does. A code arriving that early would be a live
 * bearer credential behind every abandoned checkout, so it is minted at
 * settlement instead. The migration carries the argument.
 *
 * `docs/adr/0004-a-reading-is-a-row-of-its-own.md` for why this is a line
 * bought for somebody else rather than a second order.
 */
#[Fillable(['order_line_id', 'code', 'recipient_email', 'signature', 'message', 'locale'])]
class Gift extends Model
{
    /** @use HasFactory<GiftFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
        ];
    }

    /**
     * What was bought, at the price it was that day.
     *
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    /**
     * What somebody asked for once they spent it, if anybody has.
     *
     * **At most one.** Redemption spends the gift and creates the reading in
     * one transaction, so a second reading off one gift would mean the lock
     * failed. B7 owns that.
     *
     * @return HasOne<Reading, $this>
     */
    public function reading(): HasOne
    {
        return $this->hasOne(Reading::class);
    }

    /**
     * Whether it has been spent.
     *
     * **Not "expired" and not "used up".** There is no third state: a gift is
     * outstanding or it has been redeemed, and it stays outstanding for as long
     * as that takes.
     */
    public function isRedeemed(): bool
    {
        return $this->redeemed_at !== null;
    }

    /**
     * Whether a code has been minted for it yet.
     *
     * Null between placement and settlement and never afterwards, so this
     * answers "has the money arrived" as much as it answers anything about the
     * code. Nothing can be looked up or redeemed until it is true.
     */
    public function isMinted(): bool
    {
        return $this->code !== null;
    }
}
