<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\PaymentMethod;
use Illuminate\Contracts\Container\Container;

/**
 * The ways money can arrive in this environment.
 *
 * A name from `config/payments.php` in, a `PaymentGateway` out. That is the
 * whole job, and it exists because the checkout design lets the customer pick:
 * four buttons on one page mean the method is chosen per payment, where the
 * gateway used to be chosen per environment.
 *
 * **An unrecognised name is refused.** See UnknownPaymentMethod for why that is
 * the opposite of what the newsletter registry does, and deliberate.
 *
 * Gateways are resolved fresh rather than held, so a test can name a different
 * set of methods and get them.
 */
class PaymentMethods
{
    public function __construct(private readonly Container $container) {}

    /**
     * Every method this environment offers, in the order config declares them.
     *
     * **Kept over finding 10 of the phase B review, which correctly says no
     * production code calls it yet.** The frontend has to know which buttons to
     * draw, and it cannot infer that: whether Stripe is offered depends on
     * whether that environment is configured, which is exactly the thing the
     * registry decides and nobody outside can see. Gifting adds a fourth method
     * to the same list. The endpoint that reads this is the caller, and it is
     * not built here because the frontend has not asked for it yet.
     *
     * `offers()` is the same answer for one name, and both are used by
     * PaymentMethodsTest to pin that the registry is what config says it is.
     *
     * **The endpoint that reads this exists from 29 August 2026**, and it reads
     * `offeredToCustomers()` below rather than this. `GET /api/v1/payment-methods`
     * answers what a customer may press, and this is still the whole list.
     *
     * @return array<int, string>
     */
    public function offered(): array
    {
        return array_keys($this->registry());
    }

    /**
     * The subset a customer may press, which is everything but hand settlement.
     *
     * **`manual` is genuinely offered and is genuinely not a button.** Support
     * needs it after launch, in the panel, everywhere and permanently, so
     * `offered()` is right to list it. What a reading page draws is the
     * narrower question, and a wallet or a card is the whole of the answer
     * until gifting adds `gift_code`.
     *
     * **The exclusion lives here rather than in the controller**, so the
     * comment explaining why hand settlement is offered everywhere and drawn
     * nowhere sits with the list it applies to, and PaymentMethodsTest can pin
     * it. It names `PaymentMethod::Manual` rather than the string, because the
     * registry it filters is keyed by that enum's values.
     *
     * **`PayOrderRequest` still validates against the whole registry**, and
     * narrowing a published validation rule is not this method's business. A
     * caller naming `manual` gets `nothing_to_pay` on an order that stays
     * pending, which grants nothing and settles nothing.
     *
     * @return array<int, string>
     */
    public function offeredToCustomers(): array
    {
        return array_values(array_filter(
            $this->offered(),
            fn (string $method): bool => $method !== PaymentMethod::Manual->value,
        ));
    }

    public function offers(string $method): bool
    {
        return array_key_exists($method, $this->registry());
    }

    /**
     * How money arrives for one payment.
     *
     * @param  string|null  $method  A name from config/payments.php, or null for
     *                               whichever method this environment uses when
     *                               the caller does not name one.
     *
     * @throws UnknownPaymentMethod
     */
    public function resolve(?string $method = null): PaymentGateway
    {
        $method ??= (string) config('payments.default');

        $registry = $this->registry();

        if (! array_key_exists($method, $registry)) {
            throw UnknownPaymentMethod::named($method, array_keys($registry));
        }

        return $this->container->make($registry[$method]);
    }

    /**
     * @return array<string, class-string<PaymentGateway>>
     */
    private function registry(): array
    {
        return config('payments.methods', []);
    }
}
