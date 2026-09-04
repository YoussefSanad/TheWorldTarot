<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Gift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a code is worth, to somebody holding it and deciding whether to spend it.
 *
 * @property-read Gift $resource
 */
class GiftLookupResource extends JsonResource
{
    public static $wrap = null;

    /**
     * **The copy travels with the answer, and that is not a convenience.** A
     * code for a withdrawn product still redeems, because the person paid, and
     * `/products/{key}` 404s on anything unpublished. Sending the frontend
     * there for the name would mean a page that cannot render is a present that
     * cannot be opened.
     *
     * **Never exposed: anything about the buyer.** Not the signature, not the
     * message, not the address it was sent to. The recipient's own mail carries
     * those, and this endpoint answers to anybody holding a bearer credential,
     * which is not necessarily the person it was sent to.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $line = $this->resource->orderLine;

        return [
            // Grouped, so the visitor can see what was resolved from whatever
            // they typed. The column holds it ungrouped.
            'code' => $this->resource->printedCode(),

            // The line's key rather than the product's, because a line captures
            // what was bought the way it captures the price. The enum itself,
            // not its value, so the generated specification describes a fixed
            // set the frontend can switch on.
            'product' => $line->product_key,

            // Read past `is_published`, which is the one place in this system
            // that does. `GiftController` holds the reason.
            //
            // Cast to string for the reason `ProductResource` gives: a
            // translatable attribute is an array of locales underneath, and the
            // cast is what stops the specification describing these as arrays.
            //
            // **The raw value, and not `OrderLine::displayName()`**, which every
            // other caller moved to on 4 September 2026. All three fields here
            // are the catalogue's own copy travelling with the answer, and the
            // frontend partner reads them as such. Substituting a fallback into
            // one of the three would change the API contract and leave the
            // other two blank beside it.
            'name' => (string) $line->product->name,
            'short_description' => (string) $line->product->short_description,
            'long_description' => (string) $line->product->long_description,

            // **Answered in full and without hedging.** A code that has been
            // spent says so, and says when, because that is a state the real
            // recipient has to be told about in plain words. What closes the
            // oracle that creates is the sixty bits in the code, plus the
            // throttle on the route.
            //
            // **Booleans and a date, never a sentence.** Nothing here renders
            // customer-facing copy.
            'redeemed' => $this->resource->isRedeemed(),
            'redeemed_at' => $this->resource->redeemed_at?->toIso8601String(),
        ];
    }
}
