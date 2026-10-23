<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ReadPaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ReadPaymentStatusRequest;
use App\Http\Resources\Api\V1\PaymentStatusResource;
use App\Models\Customer;
use App\Models\Order;
use App\Payments\PaymentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

/**
 * Telling a confirmation screen what happened to the money.
 *
 * **It exists because a hosted checkout hands the frontend a Checkout Session
 * id**, which is opaque and cannot be resolved with a publishable key. The only
 * party that can say what became of the payment is this one.
 *
 * **The pay token is in the body, this is a POST, and neither is ceremony.** The
 * token is the whole authority over an order, so it is a credential, and a
 * credential in a path segment is written to every access log between the
 * browser and here. That is also why nothing below ever puts it in a log line of
 * ours.
 *
 * **Read only, permanently.** `MarkOrderPaid` is the single writer of the paid
 * transition, reached by the webhook ~~and by a person in the panel~~ (**struck 22 October 2026:
 * the panel cannot mark an order paid**). This endpoint
 * may look straight at a payment that has succeeded and still must not settle
 * anything: it is polled, by a browser, on an interval nobody here chose.
 *
 * **The same departure from .claude/api.md that `PayOrderController` states.**
 * Stripe is called inside the request rather than from a queue, because the
 * answer is what the request is for and a job would finish after the response
 * that needed it. The Stripe client is bound with a ten second timeout in
 * AppServiceProvider, so an outage is a quick 503 the frontend polls past
 * rather than a pool of held workers.
 */
class OrderPaymentStatusController extends Controller
{
    public function __invoke(ReadPaymentStatusRequest $request, ReadPaymentStatus $read): JsonResponse
    {
        $order = Order::where('pay_token', $request->validated('pay_token'))->first();

        // An unknown token, a malformed one, and a token belonging to somebody
        // else all answer the same way, because the difference between them is
        // information about whose orders exist.
        if ($order === null) {
            throw new NotFoundHttpException;
        }

        $this->refuseAnybodyElse($request, $order);

        return $this->answer($this->status($read, $order), $request);
    }

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
     * The guard is named because this route carries no auth middleware, so a
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

    /**
     * **An outage is said, never guessed at.** Answering `processing` here would
     * be this application inventing a status about somebody's money. The
     * frontend polls, so 503 is the honest version of the same instruction: we
     * could not find out, ask again.
     */
    private function status(ReadPaymentStatus $read, Order $order): PaymentStatus
    {
        try {
            return $read($order);
        } catch (ApiErrorException $e) {
            // **The order id and nothing else.** The pay token is a credential
            // and this is the one place in this request that writes anywhere
            // durable. One of these is a blip and every one of them is an
            // integration that has stopped working, and the two look identical
            // without a record.
            //
            // **Once a minute per order, because this endpoint is polled.** An
            // outage is exactly when the log is worth reading, and a screen
            // asking every second would bury it under sixty identical lines a
            // minute per customer waiting. `Cache::add` is the cheap version of
            // once: it writes only if nothing is there.
            if (Cache::add('payment-status-outage:'.$order->id, true, now()->addMinute())) {
                Log::error('Stripe could not be asked what happened to a payment.', [
                    'order_id' => $order->id,
                    'reason' => $e->getMessage(),
                ]);
            }

            throw new ServiceUnavailableHttpException(null, 'The payment could not be checked. Please try again.');
        }
    }

    private function answer(PaymentStatus $status, Request $request): JsonResponse
    {
        // A held answer is a stale one about somebody's money, and it is about a
        // request nobody authenticated beyond a token. Neither a browser nor
        // anything between here and it may keep this.
        return PaymentStatusResource::make($status)
            ->toResponse($request)
            ->header('Cache-Control', 'no-store');
    }
}
