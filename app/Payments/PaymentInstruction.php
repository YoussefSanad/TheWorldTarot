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
 * **A redirect case was here and is gone**, removed 26 August 2026 as finding 10
 * of the phase B review. It had no caller and could not acquire one: checkout
 * happens in our own page precisely because no Stripe parameter can hand a
 * wallet choice to a hosted page, so sending the customer away is the design
 * this one was chosen over. A payment method that redirects can add the case
 * back, along with the caller that needs it.
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
            PaymentInstructionType::NothingToPay => ['type' => $this->type->value],
        };
    }
}
