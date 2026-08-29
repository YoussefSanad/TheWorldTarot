<?php

namespace Tests\Fixtures\Claims\Resolved;

use App\Enums\PaymentInstructionType;

/**
 * A tagged value whose docblock enumerates the cases its enum declares.
 *
 * This is the `PaymentInstruction` shape with the defect taken out: every name
 * in the list below is a case `PaymentInstructionType` actually has, so the
 * class and the enum agree and nothing is reported.
 *
 * - `NothingToPay` collects nothing and there is nowhere to send anybody
 * - `ClientSecret` hands back a credential the page confirms with
 * - `Redirect` sends the customer to a page somewhere else
 * - `RedirectSession` was the name the third of these carried in M5 Phase A
 * - `GiftCode` will answer the same as the first once gifting ships
 *
 * The last two are the reason the check reads tense at all. A reversed decision
 * is struck through and dated rather than deleted, per `CLAUDE.md` section 0.6,
 * so a name the prose marks as former or future is not a name the class claims
 * to offer today.
 */
class TaggedAnswer
{
    public function __construct(public readonly PaymentInstructionType $type) {}
}
