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
     * The alphabet a code is drawn from, and the reason it is not the obvious
     * one.
     *
     * **Crockford base32: `0-9A-Z` less `I`, `L`, `O` and `U`.** The recipient
     * reads this off a screen and types it into another one, and those four
     * are exactly the glyphs that go wrong doing it: `I` and `L` become `1`,
     * `O` becomes `0`, and `U` becomes `V` in handwriting. There are no refunds
     * and no expiry, so a code somebody cannot type is a present they cannot
     * have.
     *
     * **`U` is dropped for a second reason worth knowing**, which is Crockford's
     * own: leaving it out is what stops a random draw spelling an obscenity.
     *
     * Thirty two characters exactly, which is what makes the draw below
     * uniform without arithmetic.
     */
    public const CODE_ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    /**
     * How many of them, which is the entropy.
     *
     * Twelve characters of a thirty two character alphabet is five bits each
     * and sixty bits in total. **That number is load bearing.** `/gifts/lookup`
     * answers in full about a code it recognises, because "already redeemed" is
     * a state the real recipient has to be told about in plain words and cannot
     * hide behind the answer an unknown code gets. What closes that oracle is
     * not a vague answer, it is that nobody can guess their way to a code.
     *
     * Printed in three groups of four, so it divides by four.
     */
    public const CODE_LENGTH = 12;

    /**
     * An unguessable name for one present.
     *
     * **Not derived from anything about the gift**, which is the same argument
     * `Order::mintPayToken` makes for the token beside it. The client's own
     * example, `3CARD10021`, names its value and can be counted to, and the
     * next number along is somebody else's present.
     *
     * **`random_int` and not `Str::random`.** Not for the randomness: both read
     * from the CSPRNG and either would be unguessable. `Str::random` draws from
     * base64, whose alphabet contains `I` and `l`, `O` and `0`, and both cases
     * of every letter, so it is the one thing this code must not be. The
     * alphabet is the whole decision.
     *
     * **The rejection sampling is `random_int`'s, and there is deliberately no
     * loop of ours in front of it.** B6 (#72) asks for the code to be drawn
     * "from `random_int` with rejection sampling", and that is what this is:
     * `random_int` draws from the CSPRNG and discards out-of-range values until
     * one lands, which is exactly the technique, and it is why it is uniform
     * where `random_bytes(1)[0] % 32` would carry modulo bias. **It is uniform
     * for any bound**, not only for the thirty two here, so nothing about this
     * alphabet's size is load bearing and a shorter one would be just as
     * uniform. A rejection loop written here would be a loop that can never
     * reject, and a comment claiming it does something is worse than no loop.
     *
     * Uniqueness is the database's. `gifts.code` is uniquely indexed, and a
     * check-then-insert is two statements with a gap in the middle that a
     * second minting slips through.
     *
     * **What a collision does, since nothing here catches one.** At sixty bits
     * it is not a case to design for, and the behaviour if it ever happened is
     * still worth knowing: the insert throws inside `MarkOrderPaid`'s
     * transaction, the paid transition rolls back with it, and the order is
     * left pending. The webhook redelivers, and the second attempt draws a
     * different code. A retry loop would buy nothing that redelivery does not
     * already provide.
     */
    public static function mintCode(): string
    {
        $code = '';

        for ($character = 0; $character < self::CODE_LENGTH; $character++) {
            $code .= self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)];
        }

        return $code;
    }

    /**
     * Give this present a code, which is the moment it becomes spendable.
     *
     * **The write lives with the alphabet rather than with the caller.**
     * `MarkOrderPaid` decides *which* gifts are minted and *when*, which is a
     * question about settlement; what a minted gift is, is this model's. The
     * two were one `update()` in the action until the B6 review pointed out
     * that the action was reaching past a gift to set a gift's own column.
     *
     * **It does not check whether one is already minted.** The caller filters
     * on `isMinted()`, because deciding not to overwrite somebody's credential
     * is part of deciding what to mint, and a silent no-op here would let a
     * caller believe it had minted something.
     */
    public function mint(): void
    {
        $this->update(['code' => self::mintCode()]);
    }

    /**
     * The code as somebody reads it, `XXXX-XXXX-XXXX`.
     *
     * **Grouped for the eye and stored ungrouped.** The hyphens are
     * presentation: twelve characters in one run is a thing people lose their
     * place in halfway through, and three groups of four is the shape every
     * card in a wallet already uses. Storing them would mean the column held a
     * format as well as a value, and `/gifts/lookup` strips separators from
     * whatever was typed anyway.
     *
     * **Null until it is minted**, rather than an empty string or a row of
     * hyphens. Nothing can be looked up with an unminted gift and nothing
     * redeemed, so there is nothing to print.
     */
    public function printedCode(): ?string
    {
        return $this->code === null ? null : implode('-', str_split($this->code, 4));
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
