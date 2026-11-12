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
 */
enum ClaimLinkState: string
{
    /** `set_password_url` carries the link. */
    case Ready = 'ready';

    /** Settlement has not minted one yet. Ask again. */
    case NotYet = 'not_yet';

    /** This order will never hand the page a link. Stop asking. */
    case None = 'none';
}
