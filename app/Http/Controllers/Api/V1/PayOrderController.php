<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ProductKey;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\PayOrderRequest;
use App\Http\Resources\Api\V1\PaymentInstructionResource;
use App\Models\Customer;
use App\Models\Order;
use App\Payments\PaymentInstruction;
use App\Services\Payments\PaymentMethods;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Paying for an order that has already been placed.
 *
 * **Two calls rather than one, deliberately.** If starting the payment fails,
 * the order exists and is pending and the customer retries the payment. Folding
 * them together would mean a declined card produces another pending order every
 * time, which is a duplicate for every refusal.
 *
 * **Addressed by `pay_token`, never by id.** Order ids are sequential integers,
 * so an id in a path is a number anybody can count to and the next one along
 * belongs to somebody else. Holding the token is the whole of the authority to
 * pay, which is what lets a guest with no account come back to their order.
 *
 * Nothing about the order is echoed back. The answer says what to do next and
 * nothing else, so a guessed token leaks no amount and no email.
 *
 * **Hard rule 14, a departure from .claude/api.md stated rather than built
 * around.** That file says a slow downstream is queued and never called inside
 * a request. This endpoint calls Stripe synchronously, and it has to: the
 * address it returns is the Checkout Session the browser is about to be sent
 * to, so there is nobody to hand it to later. A job would finish after the response that needed
 * its result.
 *
 * What the rule is actually protecting against is a worker held open by
 * somebody else's outage, and that part is honoured where it can be. The Stripe
 * client is bound with a ten second timeout and five to connect, in
 * AppServiceProvider, against a library default of eighty. A Stripe outage
 * becomes a quick failure the customer can retry rather than a spinner and a
 * pool of stuck workers. Recorded 26 August 2026.
 */
class PayOrderController extends Controller
{
    public function __invoke(
        PayOrderRequest $request,
        string $payToken,
        PaymentMethods $methods,
    ): JsonResponse {
        $order = Order::where('pay_token', $payToken)->first();

        // An unknown token and a token belonging to somebody else answer the
        // same way, because the difference between them is information.
        if ($order === null) {
            throw new NotFoundHttpException;
        }

        $this->refuseAnybodyElse($request, $order);

        return $this->answer($this->instructionFor($request, $order, $methods), $request);
    }

    /**
     * **Being signed in confers nothing over another customer's order.**
     *
     * The token is the authority here, so a guest holding one is allowed and
     * that is the normal case. What is refused is somebody authenticated as
     * themselves interacting with an order that is not theirs, which is the
     * shape a leaked token plus an account would otherwise take.
     *
     * **An order belonging to nobody is not somebody else's.** Orders have been
     * allowed to wait for their customer since 29 August 2026, so a hosted
     * checkout leaves `customer_id` null until the webhook learns who paid, and
     * paying is precisely where that gap is. Comparing a key against null would
     * answer 404 to somebody signing in mid-checkout and then paying for their
     * own order, permanently, and the token is the whole authority here in any
     * case.
     *
     * The guard is named, as it is in OrderController and for the same reason:
     * this route carries no auth middleware, so a bare `user()` would ask the
     * session guard and silently see nobody when a bearer token arrived.
     */
    private function refuseAnybodyElse(PayOrderRequest $request, Order $order): void
    {
        if ($order->customer_id === null) {
            return;
        }

        $signedIn = $request->user('sanctum');

        if ($signedIn instanceof Customer && $signedIn->getKey() !== $order->customer_id) {
            throw new NotFoundHttpException;
        }
    }

    private function instructionFor(
        PayOrderRequest $request,
        Order $order,
        PaymentMethods $methods,
    ): PaymentInstruction {
        // **A second successful payment is impossible on an order already
        // paid**, and it is refused here rather than at the gateway so that
        // nothing at all is created for it. Answering rather than erroring,
        // because a customer arriving on a confirmation screen twice has done
        // nothing wrong.
        if (! $order->isPending()) {
            return PaymentInstruction::nothingToPay();
        }

        // Validated to a `ProductKey` or to nothing. The gateway is handed the
        // enum rather than a string, so nothing a caller typed can reach an
        // address even by accident.
        $returnTo = $request->validated('return_to');

        return $methods->resolve($request->validated('method'))->begin(
            $order,
            $returnTo === null ? null : ProductKey::from($returnTo),
        );
    }

    private function answer(PaymentInstruction $instruction, Request $request): JsonResponse
    {
        // A client secret is a credential with this order's money attached, so
        // the header matters as much as the body. It is set here rather than
        // left to the resource because a resource shapes what is said and this
        // is about who is allowed to keep it.
        return PaymentInstructionResource::make($instruction)
            ->toResponse($request)
            ->header('Cache-Control', 'no-store');
    }
}
