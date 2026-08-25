<?php

namespace App\Payments;

/**
 * What the customer does next to pay for an order.
 *
 * **A named value rather than a nullable string**, because three payment
 * methods give three genuinely different answers and a `?string` can only carry
 * one of them plus folklore about what null means.
 *
 * - `ManualSettlement` collects nothing and there is nowhere to send anybody
 * - `StripeCheckout` hands back a client secret, which is confirmed in the page
 * - `GiftCodeRedemption`, in the gifting milestone, will have settled the order
 *   already and answers the same as hand settlement
 *
 * `redirectTo` has no implementation today. It is here because a payment method
 * that sends the customer somewhere is a plausible thing to add, and finding out
 * later that the type cannot express it is worse than one unused constructor.
 *
 * **The frontend reads `type` and then reads the field that names it**, rather
 * than inferring from the shape of what came back. That is the whole reason
 * this is a tagged value and not three optional keys.
 */
class PaymentInstruction
{
    private function __construct(
        public readonly string $type,
        public readonly ?string $clientSecret = null,
        public readonly ?string $url = null,
    ) {}

    /**
     * Nothing to collect. The order is settled, or it waits for a person.
     */
    public static function nothingToPay(): self
    {
        return new self('nothing_to_pay');
    }

    /**
     * Confirm the payment in the page, with this.
     *
     * **A client secret is a credential with the order's money attached**, so
     * it belongs in a response that is never cached and never logged.
     */
    public static function clientSecret(string $secret): self
    {
        return new self('client_secret', clientSecret: $secret);
    }

    /**
     * Send the customer here. Nothing returns this today.
     */
    public static function redirectTo(string $url): self
    {
        return new self('redirect', url: $url);
    }

    /**
     * The answer as the frontend reads it, per section 9 of API_CONTRACT.md.
     *
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return match ($this->type) {
            'client_secret' => ['type' => $this->type, 'client_secret' => (string) $this->clientSecret],
            'redirect' => ['type' => $this->type, 'url' => (string) $this->url],
            default => ['type' => $this->type],
        };
    }
}
