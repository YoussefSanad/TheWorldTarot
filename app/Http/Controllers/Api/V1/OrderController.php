<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PlaceOrder;
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
    ): JsonResponse {
        $customer = $this->customerFor($request);

        $order = $placeOrder(
            $customer,
            (string) $request->input('currency'),
            $request->validated('lines'),
        );

        // **Nothing is collected here, and no payment is started.** This
        // records what somebody chose and what it costs, and the answer carries
        // the order's `pay_token`, which is what POST /orders/{pay_token}/pay
        // needs. Two calls rather than one, so that a payment that fails leaves
        // the order intact to retry against instead of producing a duplicate.
        return (new OrderResource($order))
            ->response()
            ->setStatusCode(201)
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Whose order this is, if anybody's yet.
     *
     * **A buyer who gave an address gets an account made from it**, with no
     * password, which cannot be signed in to until they set one. They are
     * invited to once the order is paid, never when it is placed.
     *
     * **Null is a real answer since 29 August 2026.** Checkout happens on
     * Stripe's hosted page, which collects the email after the order exists,
     * so an order can be placed by somebody we know nothing about. It is
     * filled at settlement, from the Checkout Session, before anything is
     * fulfilled. An abandoned one stays pending owned by nobody, which is safe
     * because placing an order sends no mail: there is nothing that could be
     * addressed to nobody. See
     * `docs/adr/0002-the-redirect-case-comes-back.md`.
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
    private function customerFor(StoreOrderRequest $request): ?Customer
    {
        $signedIn = $request->user('sanctum');

        if ($signedIn instanceof Customer) {
            return $signedIn;
        }

        $email = $request->validated('email');

        if (! is_string($email) || $email === '') {
            return null;
        }

        return Customer::firstOrCreate(
            ['email' => $email],
            [
                // **An address with no name is still an account**, because the
                // address is what identifies it. Stripe's page collects a name
                // too, and settlement fills this in if it is still empty.
                'name' => (string) ($request->validated('name') ?? ''),
                'password' => null,
            ],
        );
    }
}
