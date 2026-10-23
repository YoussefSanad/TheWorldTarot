<?php

namespace Tests\Fixtures\Claims\Resolved;

use App\Enums\PaymentInstructionType;

/**
 * Every claim shape this repository's comments actually make, all of them true.
 *
 * The positive case runs against the real trees as well, so this file is not
 * proof that nothing reports. It is proof that each shape is *seen* and then
 * resolved, which a clean run over `app/` cannot distinguish from a checker
 * that matched nothing at all.
 *
 * Documents: `CLAUDE.md`, `.claude/testing.md`, `docs/CHECKPOINT.md` and
 * `API_CONTRACT.md` all exist, so all four resolve.
 *
 * Config: `config/payments.php` and `config/currencies` both name a real file,
 * written with the extension and without, and `config('payments.gateways.stripe')`
 * is read for its file only. The key path is deliberately not checked, because
 * a key can be nested, conditional or written in at runtime by a package, and
 * reporting one would be reporting a claim that is true.
 *
 * Citations: `CLAUDE.md` section 0.6 and section 9 of `CLAUDE.md` are real
 * sections, and hard rule 11 is a real rule.
 *
 * **Framework and vendor names are prose, not claims.** `firstOrCreate` is
 * Laravel's, `Cache::add` is Laravel's, `ZoneSecurityIncludeHashRemoteIP` is
 * Bunny's, and `ProductCard` is a component in the frontend partner's
 * repository, which this one cannot see. None of them is a name this class
 * offers and none is reported. `StripeCheckout` is a real class elsewhere and
 * is mentioned here in passing, which is also not a claim of ownership.
 */
class EveryClaimShape
{
    public function type(): PaymentInstructionType
    {
        return PaymentInstructionType::NothingToPay;
    }

    /**
     * **A path in a string is data, not a claim.**
     *
     * This names a document that was deleted after M1 and it is not reported,
     * because a comment says something and a string holds something. Telling
     * the two apart is the whole reason this is tokenized rather than matched
     * against the file text.
     */
    public function notAClaim(): string
    {
        return 'docs/api/frontend-contract.md';
    }
}
