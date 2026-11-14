<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The one rule about who else may hold a pay token, for every route that takes
 * one in its body.
 *
 * Moved here from `OrderPaymentStatusController` on 14 November 2026, issue
 * #128, so `POST /orders/enter` asks it by the same code rather than by a copy
 * that agrees today.
 */
trait RefusesAnybodyElse
{
    /**
     * **Being signed in confers nothing over another customer's order**, exactly
     * as on `/pay`. A guest holding the token is the normal case and is allowed.
     * What is refused is somebody authenticated as themselves reading an order
     * that is not theirs.
     *
     * **An order belonging to nobody is not somebody else's.** Orders have been
     * allowed to wait for their customer since 29 August 2026, so a hosted
     * checkout leaves `customer_id` null until the webhook learns who paid, and
     * the confirmation screen is precisely where that gap is. Comparing a key
     * against null would answer 404 to somebody signing in mid-checkout and
     * then reading back their own order, permanently, and the token is the
     * whole authority here in any case.
     *
     * The guard is named because these routes carry no auth middleware, so a
     * bare `user()` would ask the session guard and see nobody when a bearer
     * token arrived.
     */
    private function refuseAnybodyElse(Request $request, Order $order): void
    {
        if ($order->customer_id === null) {
            return;
        }

        $signedIn = $request->user('sanctum');

        if ($signedIn instanceof Customer && $signedIn->getKey() !== $order->customer_id) {
            throw new NotFoundHttpException;
        }
    }
}
