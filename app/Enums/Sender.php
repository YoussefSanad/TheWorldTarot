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
 * Every address must sit on a domain verified in Resend, `theworldtarot.com`
 * as of 8 August 2026. Sending as anything else is not a configuration mistake
 * that shows up as an error, it is one that shows up as mail in a spam folder.
 *
 * **Sending from an address is not receiving at it.** Verification authorises
 * sending and creates no mailbox. A reply to any of these goes nowhere until an
 * MX record routes it somewhere real, and nobody is told that it did not
 * arrive. See CLAUDE.md section 8.
 */
enum Sender: string
{
    /** Account and access mail: password resets, and setting a password after a purchase. */
    case Support = 'support';

    /** Order confirmations to the customer. */
    case Orders = 'orders';

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
