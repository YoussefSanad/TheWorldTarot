<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The whole /payment-methods payload: which buttons this environment's reading
 * page draws.
 *
 * **An object rather than a bare array**, matching `/currencies` and unlike
 * `/languages`. Gifting brings `gift_code` and quite likely a second key
 * alongside it, describing what a code may be redeemed against, and adding a
 * key to an object is not a breaking change while replacing an array with one
 * is.
 *
 * **`methods` is a list of names, and carries nothing else.** No labels, no
 * copy and no ordering hints: what a button says is the frontend's, and this
 * repository never renders customer-facing UI. The names are the values
 * `POST /orders/{pay_token}/pay` accepts as `method`.
 *
 * **It is handed the list itself and does the keying here**, rather than being
 * handed an array already keyed `methods` and copying it across. The shape of
 * this payload is this class's business, which is the whole reason
 * `.claude/api.md` asks for a resource rather than an array from a controller.
 *
 * @property-read array<int, string> $resource
 */
class PaymentMethodIndexResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return ['methods' => $this->resource];
    }
}
