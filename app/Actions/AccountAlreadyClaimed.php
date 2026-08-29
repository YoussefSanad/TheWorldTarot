<?php

namespace App\Actions;

use RuntimeException;

/**
 * The claim lost its race, thrown from inside the broker's callback.
 *
 * **This looks like control flow through exceptions and it is not.** The
 * password broker deletes the token after the callback returns and gives the
 * callback no way to say no. A throw is the only thing that stops that
 * deletion and rolls back everything the callback wrote, so the claimant who
 * lost keeps their token rather than having it spent by the winner.
 *
 * Caught at the edge of `ClaimAccount` and turned into a `ClaimOutcome`, so it
 * never leaves that action.
 */
class AccountAlreadyClaimed extends RuntimeException
{
    public static function betweenTheCheckAndTheWrite(): self
    {
        return new self('This account was claimed between the check and the write, so this claim is refused and its token is left unspent.');
    }
}
