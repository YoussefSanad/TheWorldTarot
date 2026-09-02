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
     * Hand settlement answers this. ~~And gift code redemption will, since by
     * the time the recipient hears anything the order is already paid.~~
     * **Struck 2 September 2026: redemption is not a payment method and never
     * reaches this endpoint.** It collects nothing, so there is nothing for an
     * instruction to instruct. The case keeps its one caller and its meaning is
     * unchanged. See docs/adr/0004-a-reading-is-a-row-of-its-own.md.
     */
    case NothingToPay = 'nothing_to_pay';

    /**
     * Confirm the payment in the page, with this. A PaymentIntent's secret,
     * which the express checkout element confirms against, since
     * 29 August 2026.
     *
     * **Published, withdrawn on 26 August 2026 as a case with no caller, and
     * back three days later** because the client asked for the wallet buttons
     * on our own reading page alongside the hosted page for the card. See
     * docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md.
     *
     * **A credential with the order's money attached**, so it belongs in a
     * response that is never cached and never logged.
     */
    case ClientSecret = 'client_secret';

    /**
     * Send the browser here. Stripe's hosted Checkout Session, since
     * 29 August 2026, and the card button rather than every button.
     *
     * **Published, withdrawn on 26 August 2026 as a case with no caller, and
     * back five days later** because the frontend chose one button of a known
     * size over a wallet sheet whose height it cannot lay out around. See
     * docs/adr/0002-the-redirect-case-comes-back.md.
     */
    case Redirect = 'redirect';
}
