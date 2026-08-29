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
 * - `NothingToPay` collects nothing and there is nowhere to send anybody
 * - `ClientSecret` hands back a credential the page confirms the payment with
 * - `Redirect` sends the customer somewhere else to pay
 *
 * Three, because `PaymentInstructionType` declares three, and what each one is
 * for is written there rather than restated here. ~~Until 29 August 2026 this
 * list named `ManualSettlement`, `StripeCheckout` and `GiftCodeRedemption`~~,
 * which are the gateways that produce an instruction rather than the kinds of
 * instruction there are, and it gave `StripeCheckout` twice with two accounts
 * that contradicted each other. None of the three was ever a case. Restating an
 * account one file away is how it came to disagree with itself, and it is why
 * `tests/Feature/CommentIntegrityTest.php` now fails a build over it.
 *
 * Hand settlement answers the first of these. Gift code redemption will answer
 * it too, in the gifting milestone, since by the time the recipient hears
 * anything the order is already paid.
 *
 * **The redirect case was removed on 26 August 2026 for having no caller, and
 * is back on 29 August because it has one.** The removal's reasoning held about
 * Stripe and stopped holding about the design: the frontend cannot lay a panel
 * out around a control whose height depends on the visitor's wallet. See
 * docs/adr/0002-the-redirect-case-comes-back.md.
 *
 * **The client secret case is un-withdrawn a second time, later the same day.**
 * It was removed on 26 August as a case with no caller, restored in shape on 29
 * August, and has a caller again in `StripeWallet`, which mints a PaymentIntent
 * for the express checkout element. So the tagged union has absorbed a
 * published case being withdrawn and returned twice inside five days with no
 * breaking change, which is the whole argument for it not being a nullable
 * string. See docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md.
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
     * Confirm the payment in the page, with this: a PaymentIntent's secret,
     * minted by StripeWallet for the express checkout element.
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
