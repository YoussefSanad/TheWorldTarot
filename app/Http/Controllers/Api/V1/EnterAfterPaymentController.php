<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\EnterAfterPayment;
use App\Enums\EntryOutcome;
use App\Http\Controllers\Api\V1\Concerns\IssuesCredentials;
use App\Http\Controllers\Api\V1\Concerns\RefusesAnybodyElse;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReadPaymentStatusRequest;
use App\Http\Resources\Api\V1\CustomerResource;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Paying signs a first-time pass buyer in, issue #128, decided in #126 on
 * 14 November 2026.
 *
 * The confirmation page calls this once the payment is received, holding the
 * pay token in a body, and the buyer lands in the Viewing Room signed in with no
 * password step. The receipt still carries the set-password link for later
 * visits. Whether the token may do it is `EnterAfterPayment`'s, and the bounds
 * on it are `App\Support\ClaimLinkHandover`'s.
 *
 * **A write, so not `/orders/status`.** That endpoint is read only, permanently,
 * and polled. This one starts a session and spends a single use.
 *
 * **The website only.** A cookie session through `IssuesCredentials`, and never
 * a bearer token, `device_name` or not: a token outlives the moment by months,
 * and a pay token is not a sign in.
 *
 * **What a squatter gets, decision 2 of #126.** Somebody who pays with another
 * person's address is signed in to an account in that person's name. It reaches
 * `/me` and the films, and the owner takes it back through forgot-password:
 * Sanctum's `AuthenticateSession` signs this session out on its next request
 * once the password hash changes. `EnterAfterPaymentTest` pins that for the
 * null password this session starts with.
 */
class EnterAfterPaymentController extends Controller
{
    use IssuesCredentials;
    use RefusesAnybodyElse;

    public function __invoke(ReadPaymentStatusRequest $request, EnterAfterPayment $enter): JsonResponse
    {
        $order = Order::where('pay_token', $request->validated('pay_token'))->first();

        // Unknown, malformed and somebody else's all answer alike, as on
        // `/orders/status`, because the difference is information about whose
        // orders exist.
        if ($order === null) {
            throw new NotFoundHttpException;
        }

        $this->refuseAnybodyElse($request, $order);

        // Already in as this order's own customer: nothing to start and nothing
        // spent, so a reload of the confirmation page lands where it should.
        $signedIn = $request->user('sanctum');

        if ($signedIn instanceof Customer && $signedIn->getKey() === $order->customer_id) {
            return $this->respond(new CustomerResource($signedIn), 200);
        }

        return match ($enter($order, $request->hasSession())) {
            EntryOutcome::Enter => $this->signIn($request, $order),
            EntryOutcome::NotYet => response()->json([
                'message' => 'Your payment is still being confirmed. Please wait a moment.',
            ], 409),
            // One wording for every refusal. Which bound failed is information
            // about the account behind the address that was typed.
            EntryOutcome::Never => response()->json([
                'message' => 'Sign in to enter the Viewing Room. The link in your receipt sets your password.',
            ], 403),
        };
    }

    private function signIn(ReadPaymentStatusRequest $request, Order $order): JsonResponse
    {
        $this->startSession($request, $order->customer);

        return $this->respond(new CustomerResource($order->customer), 200);
    }
}
