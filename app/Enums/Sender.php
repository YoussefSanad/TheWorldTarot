<?php

namespace App\Enums;

use Illuminate\Mail\Mailables\Address;

/**
 * Which address a message leaves from.
 *
 * Three of them, decided 8 August 2026, because a customer reads the sender
 * before they read anything else and "who is this from" should answer itself.
 * Declared here rather than typed at each call site, so changing an address is
 * one line and not a search.
 *
 * **`Orders` was one of the three and is not one any more**, since 30 August
 * 2026. The customer's confirmation used to leave from orders@; it leaves from
 * noreply@ now, and orders@ became where "somebody is waiting for a reading"
 * lands instead. A mailbox we only ever write to is a different kind of thing
 * from a name we send under, so it lives in `mail.notify` and not here.
 *
 * Every address must sit on a domain verified in Resend, `theworldtarot.com`
 * as of 8 August 2026. Sending as anything else is not a configuration mistake
 * that shows up as an error, it is one that shows up as mail in a spam folder.
 *
 * **Sending from an address is not receiving at it.** Verification authorises
 * sending and creates no mailbox. A reply to any of these goes nowhere until an
 * MX record routes it somewhere real, and nobody is told that it did not
 * arrive. See CLAUDE.md section 8.
 *
 * That is most of what `NoReply` says out loud, and it is not all of what was
 * asked for. Nothing in this enum can stop somebody replying, it can only stop
 * us inviting them to. Whether a reply bounces back to the customer or vanishes
 * is decided by an MX record, not by any line of this.
 */
enum Sender: string
{
    /** Account and access mail: password resets, and setting a password after a purchase. */
    case Support = 'support';

    /**
     * The customer's order confirmation.
     *
     * Named for what it promises rather than for what it carries, because with
     * this message the sender is half the message. Jennifer asked on 30 August
     * 2026 that a confirmation not invite a reply, and a customer reads who it
     * came from before they read a word of it.
     */
    case NoReply = 'noreply';

    /** Telling Jennifer a reading is waiting, and delivering the reading itself from M7. */
    case Fulfillment = 'fulfillment';

    public function address(): Address
    {
        return new Address(
            config('mail.senders.'.$this->value),
            // One display name for all three. The address says which part of
            // the business is writing, the name says who the business is.
            config('mail.from.name'),
        );
    }
}
