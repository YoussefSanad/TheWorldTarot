<?php

namespace App\Enums;

/**
 * Where an order is in its life, as far as money is concerned.
 *
 * **Only money.** Whether the reading has been written and sent is
 * `fulfilled_at` on the order, not a case here, because the two are genuinely
 * independent: "paid for and not yet written" is the normal state of every
 * manual reading, and a single enum trying to hold both cannot express it.
 *
 * A string column with a PHP enum cast rather than a Postgres enum type, per
 * .claude/database.md, since altering one of those later is painful.
 */
enum OrderStatus: string
{
    /** Placed, nothing collected. Where every order starts. */
    case Pending = 'pending';

    /** The money arrived. The only state that grants anything. */
    case Paid = 'paid';

    /** Abandoned or refused before payment. An end, not a pause. */
    case Cancelled = 'cancelled';

    /**
     * What Jennifer sees, since none of the words above are hers.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Awaiting payment',
            self::Paid => 'Paid',
            self::Cancelled => 'Cancelled',
        };
    }
}
