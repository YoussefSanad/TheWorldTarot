<?php

namespace App\Enums;

/**
 * What happened when somebody followed a claim link.
 *
 * Three cases rather than a boolean, on the argument `PaymentInstruction`
 * already makes: a scalar can carry one of them plus folklore about what the
 * others mean. There are genuinely three answers here and the endpoint says
 * something different about each.
 */
enum ClaimOutcome
{
    /** A first password is now set, and they can sign in with it. */
    case Claimed;

    /**
     * There was nothing here to claim.
     *
     * **Covers an address with no account as well as an account that already
     * has a password**, and folding the two together is the point rather than
     * an approximation. Separating them would turn a claim link into a way to
     * ask whether an address has an account here and whether somebody has
     * already claimed it, which is the question this whole road refuses.
     */
    case AlreadyClaimed;

    /** The token was never real, has been spent, or is older than an hour. */
    case LinkNotValid;
}
