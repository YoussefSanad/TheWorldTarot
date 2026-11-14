<?php

namespace App\Enums;

/**
 * Whether the confirmation page has a claim link to show, issue #122.
 *
 * **Three answers, not two, because the page has to know when to stop asking.**
 * The card road paints `received` before the webhook settles the order, and the
 * link exists only once settlement has minted it. "Not yet" tells the page to
 * ask again in the background; "none" tells it the answer will never change
 * for this order, so it can settle on the email step and stop.
 *
 * **"None" deliberately covers a buyer who already has a password** as well as
 * every other order that never gets a link. Telling the two apart was offered
 * in #122 and declined: the page renders the same fallback for both, and a
 * separate answer would tell whoever holds a pay token whether the address
 * they typed at Stripe already has a claimed account here.
 *
 * **Only `None` is answered since 14 November 2026**, #128's second step, which
 * retired handing the page a link. `Ready` and `NotYet` stay as the contract's
 * published vocabulary (`API_CONTRACT.md` still documents all three, struck
 * through), and nothing here produces them. The enum goes in the follow-up
 * that removes the keys.
 */
enum ClaimLinkState: string
{
    /** ~~`set_password_url` carries the link.~~ Never answered since #128. */
    case Ready = 'ready';

    /** ~~Settlement has not minted one yet. Ask again.~~ Never answered since #128. */
    case NotYet = 'not_yet';

    /** This order will never hand the page a link. Stop asking. Every order, since #128. */
    case None = 'none';
}
