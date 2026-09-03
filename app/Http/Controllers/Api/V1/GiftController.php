<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\RedeemGift;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LookUpGiftRequest;
use App\Http\Requests\Api\V1\RedeemGiftRequest;
use App\Http\Resources\Api\V1\GiftLookupResource;
use App\Http\Resources\Api\V1\RedeemedGiftResource;
use App\Models\Gift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Looking a code up, and spending it.
 *
 * **Two calls on purpose, and the reason is the recipient's inbox.** Email
 * scanners and link prefetchers follow links, so a page that spent the code on
 * arrival would hand a present to whatever opened the mail first, and there are
 * no refunds to put that right with. The frontend's ADR on redemption being a
 * page of its own decides it, named in prose because its path resolves only in
 * that repository: resolving is one call, spending is another, and spending
 * happens on submit.
 *
 * **Both are POSTs, and neither is ceremony.** The code is a bearer credential,
 * and a credential in a path segment or a query string is written to every
 * access log and every proxy between the browser and here. `POST /orders/status`
 * takes the pay token the same way. Neither of these ever puts a code in a log
 * line of ours either.
 *
 * **Behind the language segment**, unlike orders and payment, because this one
 * genuinely answers content: the reading's name and copy come back with the
 * lookup, in the language the visitor opened the link in.
 */
class GiftController extends Controller
{
    /**
     * What a code is worth, without spending it.
     */
    public function lookup(LookUpGiftRequest $request): JsonResponse
    {
        return $this->answer(
            GiftLookupResource::make($this->giftTypedAs($request->string('code')->value())),
            $request,
        );
    }

    /**
     * Spending it, which is the moment the reading is asked for.
     *
     * **The confirmation is this response.** It does not point the frontend at
     * `POST /orders/status`, which reports a payment that does not happen here.
     */
    public function redeem(RedeemGiftRequest $request, RedeemGift $redeem): JsonResponse
    {
        $reading = $redeem(
            $this->giftTypedAs($request->string('code')->value()),
            $request->string('question')->value(),
            $request->string('querent_email')->value(),
            $request->input('querent_name'),
        );

        return $this->answer(RedeemedGiftResource::make($reading), $request);
    }

    /**
     * The present somebody typed this code for.
     *
     * **One resolution for both endpoints**, so a code that resolves on the
     * page cannot fail to resolve on submit. The normalising itself lives on
     * `Gift`, beside the alphabet that makes it unambiguous.
     *
     * **A code that never existed, one mistyped past reading, and one whose
     * money never arrived all answer the same 404.** Telling them apart is a
     * way of asking whether a guess got closer, and the 404 carries a reason
     * because an `HttpException` with no message renders as `{"message": ""}`
     * once debug is off.
     *
     * **What is deliberately not 404 is a code already spent.** That is a state
     * the real recipient has to be told about, so the lookup answers it in full
     * and `RedeemGift` refuses it with a 409.
     */
    private function giftTypedAs(string $typed): Gift
    {
        // **The product is loaded past `is_published` and past a soft delete,
        // and this is the one place in the system that reads the catalogue that
        // way.** A withdrawn reading still redeems, because the person paid for
        // it, and `Product::availableIn` would answer nothing for one. The
        // relation carries `withTrashed()` for the same reason an old order can
        // still say what it was.
        $gift = Gift::withTypedCode($typed)->with('orderLine.product')->first();

        if ($gift === null) {
            throw new NotFoundHttpException('No present was found for that code.');
        }

        return $gift;
    }

    /**
     * **200, and never cached.**
     *
     * A response naming what a bearer credential is worth is not cacheable by a
     * browser, by a service worker, or by anything in between.
     *
     * **The status is set rather than left to the resource**, because a
     * resource wrapping a model that was created during the request answers 201
     * by itself, and redemption creates one. `API_CONTRACT.md` says 200 for
     * both of these, and it is the right answer: a 201 offers the address of
     * the thing that was made, and nothing on the frontend addresses a reading.
     */
    private function answer(JsonResource $resource, Request $request): JsonResponse
    {
        return $resource->toResponse($request)
            ->setStatusCode(SymfonyResponse::HTTP_OK)
            ->header('Cache-Control', 'no-store');
    }
}
