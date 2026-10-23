<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\PaymentMethodIndexResource;
use App\Services\Payments\PaymentMethods;

class PaymentMethodController extends Controller
{
    /**
     * The ways a customer can pay in this environment.
     *
     * **The frontend cannot infer this**, which is the whole reason it exists.
     * Whether the wallet button is drawn at all depends on whether this
     * environment configured Stripe, and that is a decision `config/payments.php`
     * makes and nobody outside can see. The panel draws one button or two on
     * this answer.
     *
     * ~~**Hand settlement is deliberately absent.** It is offered, everywhere
     * and permanently, to a person in the admin panel.~~ **Struck 22 October
     * 2026: hand settlement is retired**, so there is nothing to exclude.
     *
     * Flat, with no language segment, for the reason `/currencies` is flat: it
     * carries no copy. It also varies by nothing about the visitor, unlike
     * `detected` there, so it is cacheable in a way that endpoint is not.
     */
    public function __invoke(PaymentMethods $methods): PaymentMethodIndexResource
    {
        return new PaymentMethodIndexResource($methods->offeredToCustomers());
    }
}
