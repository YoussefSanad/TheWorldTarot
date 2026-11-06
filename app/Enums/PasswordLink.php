<?php

namespace App\Enums;

/**
 * Which page a password token in a receipt is printed as a link to.
 *
 * **Both cases are the same broker token**, from the same table, and either
 * page would accept it for the right account. What differs is which page works
 * for the person reading the mail: set-password refuses an account that already
 * has a password (`ClaimAccount` answers `AlreadyClaimed`), and "reset your
 * password" is confusing wording for somebody who has never had one.
 *
 * **Decided once, when the token is minted under the customer lock**, by
 * `MarkOrderPaid::tell()`, and carried to `OrderPaid` with the token. The mail
 * prints that kind of link or none, and never re-decides from the account as it
 * stands when the queue gets round to sending. Issue #114.
 */
enum PasswordLink
{
    /** A first password, for an account nobody has claimed. */
    case Set;

    /** A new password, for an account that already has one. */
    case Reset;
}
