<?php

namespace App\Enums;

/**
 * The kinds of answer the pay endpoint can give.
 *
 * **A published contract**, section 9 of API_CONTRACT.md. The frontend reads
 * `type` and then reads the field that names it, so these values are the
 * frontend partner's switch statement and are not free to change.
 *
 * An enum rather than the raw strings this began with, per the repo convention
 * and because those strings were written in one place and matched in another,
 * two lines apart, with nothing tying them together.
 */
enum PaymentInstructionType: string
{
    /**
     * Nothing to collect. The order is settled, or it waits for a person.
     *
     * Hand settlement answers this, and gift code redemption will, since by the
     * time the recipient hears anything the order is already paid.
     */
    case NothingToPay = 'nothing_to_pay';

    /**
     * Confirm the payment in the page, with this.
     *
     * **A credential with the order's money attached**, so it belongs in a
     * response that is never cached and never logged.
     */
    case ClientSecret = 'client_secret';
}
