<?php

namespace App\Payments;

/**
 * Everything `POST /orders/status` answers, issue #122.
 *
 * Two values and no model, so `PaymentStatusResource` still has nothing to
 * reach for beyond what is deliberately handed to it. The claim link half is
 * always `ClaimLinkForThePage::none()` since #128's retire step, 14 November
 * 2026.
 */
final class Confirmation
{
    public function __construct(
        public readonly PaymentStatus $payment,
        public readonly ClaimLinkForThePage $claimLink,
    ) {}

    /**
     * @return array{status: string, set_password_link: string, set_password_url: ?string}
     */
    public function toArray(): array
    {
        return [...$this->payment->toArray(), ...$this->claimLink->toArray()];
    }
}
