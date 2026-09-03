<?php

namespace App\Models;

use Database\Factories\GiftFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
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
     * The three glyphs a code is read back through, and why there are three.
     *
     * **`I` and `L` are read as `1`, `O` as `0`.** They are the mistakes
     * somebody makes copying twelve characters off one screen and typing them
     * into another, and `CODE_ALPHABET` above leaves all three out precisely so
     * that reading them this way cannot be wrong: no minted code contains one,
     * so nothing here can turn a real code into a different real code.
     *
     * **`U` is left out of the alphabet and is deliberately not here.** It is
     * dropped so that a draw cannot spell an obscenity, not because anybody
     * mistypes it, so there is nothing to read it as.
     *
     * @var array<string, string>
     */
    public const CODE_SUBSTITUTIONS = ['I' => '1', 'L' => '1', 'O' => '0'];

    /**
     * What somebody typed, read as the code they were given.
     *
     * **Here rather than in the endpoints, because the reading is only
     * unambiguous next to the alphabet it is read against.** `API_CONTRACT.md`
     * specifies it under "Gifts" and both endpoints owe the same answer: a code
     * that resolves on the page has to resolve on submit, or somebody watches
     * their present stop existing between one screen and the next.
     *
     * Uppercase first, so a lowercase `l` is read the same as an upper one.
     * Then everything that is not a letter or a digit goes, which covers the
     * hyphens this application printed, the spaces a mail client wrapped in,
     * and the quotation marks that come with a paste. Then the three
     * substitutions.
     *
     * **A byte class rather than a Unicode one, and that is the safe way
     * round.** Anything outside ASCII is not in the alphabet, so stripping it
     * byte by byte removes exactly what could never have been part of a code.
     *
     * **Nonsense normalises to nonsense rather than to an error.** A string
     * with nothing readable in it comes back empty, is looked up, and misses,
     * because telling "malformed" apart from "unknown" is a way of asking
     * whether a guess got closer.
     */
    public static function normaliseCode(string $typed): string
    {
        $letters = preg_replace('/[^A-Z0-9]/', '', strtoupper($typed));

        return strtr((string) $letters, self::CODE_SUBSTITUTIONS);
    }

    /**
     * The present somebody typed the code for, if there is one.
     *
     * **A scope rather than a finder, because redemption needs the row under a
     * lock** and a method that handed back a model could not give it one. Both
     * endpoints reach the same normalising this way, which is what stops a code
     * resolving on the page and missing on submit.
     *
     * **An unminted gift is unreachable through this and needs no clause
     * saying so.** Its `code` is null, null is not equal to any string in SQL,
     * and a string that normalises to nothing matches no row either, because
     * every code is twelve characters. That is the whole of why an abandoned
     * checkout leaves no live credential.
     *
     * @param  Builder<Gift>  $query
     * @return Builder<Gift>
     */
    public function scopeWithTypedCode(Builder $query, string $typed): Builder
    {
        return $query->where('code', self::normaliseCode($typed));
    }

    /**
     * Rows whose code contains what somebody typed.
     *
     * **Partial, where `scopeWithTypedCode` above is exact**, because the two
     * answer different questions. Redemption is handed a whole code and must
     * match one row or none. A panel search box is handed whatever she could
     * read off a mail or was told over the phone.
     *
     * **Read through the same normalising**, which is the point of it being
     * here rather than in the table. A code is stored in one run and quoted in
     * three groups, so a column that searched what it stored would mean the one
     * form of it anybody ever says out loud finds nothing.
     *
     * **Nothing readable finds nothing**, and that clause is the whole reason
     * this is a scope. `normaliseCode` states the rule for its own callers,
     * that nonsense normalises to nonsense and then misses, and a `like` would
     * silently invert it: an empty needle matches every row rather than none,
     * so a search box holding one stray character would answer with the entire
     * liability.
     *
     * @param  Builder<Gift>  $query
     * @return Builder<Gift>
     */
    public function scopeContainingTypedCode(Builder $query, string $typed): Builder
    {
        $code = self::normaliseCode($typed);

        if ($code === '') {
            return $query->whereRaw('false');
        }

        return $query->where('code', 'like', '%'.$code.'%');
    }

    /**
     * The presents that are still owed.
     *
     * **Asked here rather than spelled out at each call site**, for the reason
     * `CONTEXT.md` gives about `Customer::isUnclaimed()`: three modules once
     * wrote `password === null` separately and agreed only because all three
     * happened to write it the same way. The gifts screen asks this twice, from
     * its badge and from the filter it opens on, and a number in the sidebar
     * that disagreed with the list under it would be worse than no number.
     *
     * **Minted and unredeemed, both halves.** `redeemed_at IS NULL` alone is
     * how `docs/adr/0005-gift-codes-do-not-expire.md` puts it, and that
     * sentence predates the row existing before its code does. A gift with no
     * code took no money and told nobody, so counting it here would put the
     * liability out by every abandoned checkout.
     *
     * @param  Builder<Gift>  $query
     * @return Builder<Gift>
     */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereNotNull('code')->whereNull('redeemed_at');
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
