<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PlaceOrder;
use App\Contracts\PaymentGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreOrderRequest;
use App\Http\Resources\Api\V1\OrderResource;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;

/**
 * Placing an order.
 *
 * Open to anybody, signed in or not. Buying is the moment most people meet this
 * system, and demanding an account first costs real sales at exactly the wrong
 * moment.
 */
class OrderController extends Controller
{
    public function __invoke(
        StoreOrderRequest $request,
        PlaceOrder $placeOrder,
        PaymentGateway $gateway,
    ): JsonResponse {
        $customer = $this->customerFor($request);

        $order = $placeOrder(
            $customer,
            (string) $request->input('currency'),
            $request->validated('lines'),
        );

        // Whatever the customer does next, which is nothing at all while orders
        // are settled by hand. Resolved from config/payments.php.
        $next = $gateway->begin($order);

        return (new OrderResource($order, $next))
            ->response()
            ->setStatusCode(201)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Whose order this is.
     *
     * **Every order belongs to a customer**, so a buyer without an account gets
     * one made from the email they gave. It has no password and cannot be
     * signed in to until they set one, which they are invited to do once the
     * order is paid, never when it is placed.
     *
     * Somebody already signed in uses their own account and whatever they typed
     * is ignored, so an order cannot be attached to another person by anybody
     * authenticated as themselves.
     *
     * **That protection is why the guard is named here.** This route carries no
     * auth middleware, so nobody has resolved the caller yet, and a bare
     * `user()` asks the default guard, which only reads sessions. A customer
     * carrying a bearer token came through as a guest and their order attached
     * to whatever address was typed, which is exactly the thing the paragraph
     * above says cannot happen. The website was never affected, because a
     * cookie is what the default guard does understand. Found on staging on
     * 13 August 2026 by tests/Staging, whose walk signs in with a token.
     *
     * `firstOrCreate` is safe against two first orders arriving together: the
     * framework catches the unique violation on `email` inside a savepoint and
     * re-reads, rather than the second buyer seeing an error.
     */
    private function customerFor(StoreOrderRequest $request): Customer
    {
        $signedIn = $request->user('sanctum');

        if ($signedIn instanceof Customer) {
            return $signedIn;
        }

        return Customer::firstOrCreate(
            ['email' => $request->string('email')->value()],
            [
                'name' => $request->string('name')->value(),
                'password' => null,
            ],
        );
    }
}
