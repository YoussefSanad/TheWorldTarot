<?php

namespace App\Payments;

use App\Enums\ClaimLinkState;

/**
 * What the confirmation page is told about a claim link, issue #122.
 *
 * ~~**The URL is whole or absent, never in parts and never an empty string.**
 * Only `ready()` can carry one.~~ **Retired 14 November 2026, #128's second
 * step: the page is never handed a link.** `ready()` and `notYet()` are gone
 * with `ReadClaimLink`, their only caller, so `none()` is the one answer this
 * can give: `set_password_link: "none"`, `set_password_url: null`.
 *
 * **It survives only to keep the two keys on `POST /orders/status`**, so a site
 * that still reads them is not broken. It goes, with `ClaimLinkState` and this
 * half of `Confirmation`, in the follow-up that removes the keys once the
 * frontend has stopped reading them (#126, "what happens to the in-flight
 * work").
 */
final class ClaimLinkForThePage
{
    private function __construct(
        public readonly ClaimLinkState $state,
        public readonly ?string $url,
    ) {}

    public static function none(): self
    {
        return new self(ClaimLinkState::None, null);
    }

    /**
     * @return array{set_password_link: string, set_password_url: ?string}
     */
    public function toArray(): array
    {
        return [
            'set_password_link' => $this->state->value,
            'set_password_url' => $this->url,
        ];
    }
}
