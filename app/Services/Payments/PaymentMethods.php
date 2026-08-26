<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
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
     * @return array<int, string>
     */
    public function offered(): array
    {
        return array_keys($this->registry());
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
