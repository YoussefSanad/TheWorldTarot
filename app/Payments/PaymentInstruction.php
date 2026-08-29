<?php

namespace App\Payments;

use App\Enums\PaymentInstructionType;

/**
 * What the customer does next to pay for an order.
 *
 * **A named value rather than a nullable string**, because the payment methods
 * give genuinely different answers and a `?string` can only carry one of them
 * plus folklore about what null means.
 *
 * - `ManualSettlement` collects nothing and there is nowhere to send anybody
 * - `StripeCheckout` hands back a client secret, which is confirmed in the page
 * - `GiftCodeRedemption`, in the gifting milestone, will have settled the order
 *   already and answers the same as hand settlement
 *
 * - `StripeCheckout` sends the customer to a hosted Checkout Session, which is
 *   a redirect
 *
 * **The redirect case was removed on 26 August 2026 for having no caller, and
 * is back on 29 August because it has one.** The removal's reasoning held about
 * Stripe and stopped holding about the design: the frontend cannot lay a panel
 * out around a control whose height depends on the visitor's wallet. See
 * docs/adr/0002-the-redirect-case-comes-back.md.
 *
 * **The frontend reads `type` and then reads the field that names it**, rather
 * than inferring from the shape of what came back. That is the whole reason
 * this is a tagged value and not a bag of optional keys.
 */
class PaymentInstruction
{
    private function __construct(
        public readonly PaymentInstructionType $type,
        public readonly ?string $clientSecret = null,
        public readonly ?string $redirectUrl = null,
    ) {}

    /**
     * Nothing to collect. The order is settled, or it waits for a person.
     */
    public static function nothingToPay(): self
    {
        return new self(PaymentInstructionType::NothingToPay);
    }

    /**
     * Confirm the payment in the page, with this.
     *
     * **A client secret is a credential with the order's money attached**, so
     * it belongs in a response that is never cached and never logged.
     */
    public static function clientSecret(string $secret): self
    {
        return new self(PaymentInstructionType::ClientSecret, clientSecret: $secret);
    }

    /**
     * Send the customer here to pay: a Stripe hosted Checkout Session.
     *
     * **Never persisted.** A Session expires after 24 hours and an order never
     * does, so the address is only ever the answer to the call that minted it.
     */
    public static function redirect(string $url): self
    {
        return new self(PaymentInstructionType::Redirect, redirectUrl: $url);
    }

    /**
     * The answer as the frontend reads it, per section 9 of API_CONTRACT.md.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return match ($this->type) {
            PaymentInstructionType::ClientSecret => [
                'type' => $this->type->value,
                'client_secret' => (string) $this->clientSecret,
            ],
            PaymentInstructionType::Redirect => [
                'type' => $this->type->value,
                'redirect_url' => (string) $this->redirectUrl,
            ],
            PaymentInstructionType::NothingToPay => ['type' => $this->type->value],
        };
    }
}
