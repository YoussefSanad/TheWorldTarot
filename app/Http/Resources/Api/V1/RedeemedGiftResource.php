<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Reading;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The confirmation, answered by the call that spent the code.
 *
 * **This is the whole confirmation and there is no second call.** The only
 * other endpoint that could answer one is `POST /orders/status`, which reports
 * a payment, and the payment behind a redeemed gift happened months earlier to
 * somebody else. `API_CONTRACT.md` tells the frontend not to reach for it.
 *
 * @property-read Reading $resource
 */
class RedeemedGiftResource extends JsonResource
{
    public static $wrap = null;

    /**
     * **`asked_at` is when the clock starts**, and it is here so that a
     * delivery window can be stated relative to it as a property of the reading
     * rather than as a promise this response makes. The promise itself is made
     * once, in the mail the querent gets.
     *
     * **Never exposed: the reading's id, the gift, or the order behind it.**
     * Nothing on the frontend addresses a reading, and naming the order would
     * hand somebody who redeemed a present a handle on the buyer's purchase.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $line = $this->resource->orderLine;

        return [
            // The enum, as on the lookup, so the two answers describe the same
            // product the same way.
            'product' => $line->product_key,

            // **The raw translatable value, and not `OrderLine::displayName()`,
            // which every other caller moved to on 4 September 2026.** The cast
            // is what stops the generated specification describing this as an
            // array of locales, the same reason `GiftLookupResource` gives, and
            // that field is what the frontend partner reads. Giving it a
            // fallback name would change the API contract, so it keeps the
            // contract and the panel keeps the fallback. The cost is real and
            // known: a product with no name in any locale answers `""` here
            // while every screen and every message names it.
            'name' => (string) $line->product->name,

            // Echoed back so the confirmation can show somebody what they
            // asked. This is the question of record, and not
            // `order_lines.question`, which is null on a gift line.
            'question' => $this->resource->question,
            'querent_email' => $this->resource->querent_email,

            'asked_at' => $this->resource->asked_at->toIso8601String(),
        ];
    }
}
