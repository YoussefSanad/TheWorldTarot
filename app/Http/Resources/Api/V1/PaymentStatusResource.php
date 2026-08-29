<?php

namespace App\Http\Resources\Api\V1;

use App\Payments\PaymentStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * How far along a payment is, as the frontend sees it.
 *
 * **Unwrapped, like every other resource here**, so the body is the flat
 * `{ "status": "..." }` section 2 of API_CONTRACT.md publishes.
 *
 * **No order state reaches this, and that is load-bearing.** The pay token is
 * the whole authority on this route, so a guessed one must leak no amount, no
 * email and no id. `PaymentStatus` carries none of them, and this resource must
 * never be handed a model to reach for.
 *
 * @property-read PaymentStatus $resource
 */
class PaymentStatusResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
