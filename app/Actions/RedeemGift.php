<?php

namespace App\Actions;

use App\Models\Gift;
use App\Models\Reading;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one moment a present is spent, and the asking that is the whole of it.
 *
 * **Nothing is collected here.** Redemption was designed as a fourth payment
 * method and that was withdrawn on 1 September 2026, before it ever had a
 * caller: the buyer paid months earlier, so there is no payment for a gateway
 * to make and no second order for one to settle.
 * `docs/adr/0004-a-reading-is-a-row-of-its-own.md` carries the argument, and
 * what it leaves behind is this: a gift is stamped, and a reading starts
 * existing.
 *
 * ## The lock is the whole of this class
 *
 * **This is the endpoint where a mistake means free readings.** Two tabs, or a
 * submit and the impatient one behind it, are two requests at one row, and
 * without the lock both read `redeemed_at IS NULL`, both pass the guard, and
 * both create a reading. There are no refunds and no expiry, so the second one
 * is a reading written and given away with nothing to take it back with.
 *
 * The shape is `MarkOrderPaid`'s, deliberately, because it is the same problem:
 * the row is re-read under `lockForUpdate` inside the transaction, the guard is
 * asked of the locked row rather than of the one handed in, and everything that
 * follows is inside the branch only a real transition reaches. The loser of a
 * race waits on the lock, sees the stamp the winner committed, and is refused.
 *
 * `tests/Feature/RedeemAGiftRaceTest.php` is the only proof of any of that, and
 * it races two real processes to get it.
 *
 * ## Ordering
 *
 * The stamp and the reading are inside one transaction, so a failure between
 * them cannot leave a present marked spent with nothing asked for. The mail
 * waits for the commit, which is `AskForReading`'s to arrange and its own
 * docblock's to explain.
 */
class RedeemGift
{
    public function __construct(private readonly AskForReading $askForReading) {}

    /**
     * @return Reading What was asked for, which is what the confirmation says
     *
     * @throws ValidationException When the present has already been spent
     */
    public function __invoke(Gift $gift, string $question, string $querentEmail, ?string $querentName = null): Reading
    {
        return DB::transaction(function () use ($gift, $question, $querentEmail, $querentName): Reading {
            // **Re-read under the lock rather than trusting the instance.** The
            // gift the caller resolved was read outside this transaction, so
            // its `redeemed_at` is a fact about a moment that has passed. Only
            // the locked row can be asked.
            $locked = Gift::whereKey($gift->getKey())->lockForUpdate()->first();

            // Null is a gift deleted between the lookup and here, which nothing
            // does today. Refused the same way, because the answer to "can this
            // be spent" is no either way and there is nothing to fix by asking
            // again.
            if ($locked === null || $locked->isRedeemed()) {
                throw self::alreadySpent();
            }

            $locked->forceFill(['redeemed_at' => now()])->save();

            return $this->askForReading->forGift($locked, $question, $querentEmail, $querentName);
        });
    }

    /**
     * The refusal, and why it is a 409 wearing a 422's clothes.
     *
     * **Not a 404**, because the difference between "you mistyped it" and "this
     * has already been used" is exactly what the real recipient has to be told.
     * A present that has been opened is a thing that happened to somebody, and
     * hiding it behind the answer a stranger's guess gets would leave them
     * retyping a code that will never work.
     *
     * **Not a 422**, because a 422 says fix what you sent and send it again,
     * and there is nothing here to fix. `API_CONTRACT.md` section 7 introduced
     * 409 for this one caller.
     *
     * **`ValidationException` rather than a bespoke exception**, because the
     * contract's reason for choosing 409 is that it carries a 422's body so the
     * frontend's existing error handling reads it unchanged. That body is
     * exactly what this class renders, keyed by the field that was sent, and
     * writing a second thing that produces the same shape is how the two come
     * apart.
     */
    private static function alreadySpent(): ValidationException
    {
        return ValidationException::withMessages([
            'code' => ['This gift has already been redeemed.'],
        ])->status(409);
    }
}
