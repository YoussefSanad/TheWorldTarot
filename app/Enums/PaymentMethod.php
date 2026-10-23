<?php

namespace App\Enums;

/**
 * The ways money can arrive, by name.
 *
 * **A published contract**, section 2 of API_CONTRACT.md. `GET /api/v1/payment-methods`
 * answers these values, and `POST /api/v1/orders/{pay_token}/pay` accepts them
 * as `method`. They are the frontend partner's switch statement and are not
 * free to change.
 *
 * An enum rather than the raw strings this began with, per the repo convention
 * and for the reason PaymentInstructionType gives beside it: the names were
 * written in config/payments.php and matched again in a request rule, a
 * resource and the staging walk, with nothing tying any of them together.
 *
 * **config/payments.php stays the registry, and this enum does not replace it.**
 * A case here is a name that exists. Whether an environment offers it is that
 * file's decision and depends on what is configured, which is why the registry
 * is still what `PaymentMethods` reads and what `PayOrderRequest` validates
 * against. PaymentsConfigTest pins that every key registered there is a case
 * here, so the two cannot drift.
 */
enum PaymentMethod: string
{
    /**
     * Card, on Stripe's own hosted page. The card button.
     *
     * Answers `PaymentInstructionType::Redirect`, a Checkout Session's URL.
     */
    case Stripe = 'stripe';

    /**
     * Apple Pay and Google Pay, in the express checkout element on the
     * frontend's own reading page. The wallet button.
     *
     * Answers `PaymentInstructionType::ClientSecret`, a PaymentIntent's secret.
     * Added 29 August 2026. It is a second method rather than a flag on the one
     * above because a hosted Session and a PaymentIntent are different objects
     * at Stripe, and no parameter reconciles them. See
     * docs/adr/0003-the-wallet-keeps-its-own-payment-intent.md.
     */
    case StripeWallet = 'stripe_wallet';
}
