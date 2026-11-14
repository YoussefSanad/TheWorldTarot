<?php

namespace App\Http\Resources\Api\V1;

use App\Payments\Confirmation;
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
 * ~~email~~ and no id. `PaymentStatus` carries none of them, and this resource
 * must never be handed a model to reach for.
 *
 * ~~**Changed 11 November 2026, issue #122: the email can come back**, inside
 * the claim link a first-time pass buyer is handed for fifteen minutes after
 * settlement.~~ **Reversed 14 November 2026, #128's second step: no email comes
 * back again**, because no link does. `set_password_link` is `"none"` and
 * `set_password_url` is `null` on every answer, kept only so a site that still
 * reads them is not broken. Still never an amount or an id. The resource is
 * handed a `Confirmation`, two values and no model, so that stays true.
 *
 * @property-read Confirmation $resource
 */
class PaymentStatusResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{status: string, set_password_link: string, set_password_url: ?string}
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
