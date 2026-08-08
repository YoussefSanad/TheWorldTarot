<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Product;
use App\Support\RequestContext;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Product
 */
class ProductResource extends JsonResource
{
    public static $wrap = null;

    /**
     * Translatable values come out in the locale the middleware resolved, since
     * it has already set the application locale for this request.
     *
     * Never exposed: `key` and `fulfillment`. Both are internal structure, and a
     * customer has no business knowing how their reading gets written.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $currency = app(RequestContext::class)->currency;

        // Cast explicitly to string. A translatable attribute is an array of
        // locales underneath, and while the accessor already returns the active
        // locale's value, the cast is what stops the generated specification
        // describing these as arrays to the frontend.
        return [
            // The enums themselves, not their values. Both serialise to the
            // same string, and returning the enum is what makes the generated
            // specification describe them as a fixed set rather than "string",
            // so the frontend gets a union type it can switch on exhaustively.
            'key' => $this->key,
            'type' => $this->type,
            'name' => (string) $this->name,
            'short_description' => (string) $this->short_description,

            // Only on the single product endpoint. A listing does not need the
            // long copy, and sending it would bloat every card on the page.
            'long_description' => $this->when(
                $request->routeIs('products.show'),
                fn (): string => (string) $this->long_description,
            ),

            // Whether the customer may attach a question when buying. Always
            // optional, never required.
            'allows_question' => $this->allows_question,

            // Exactly one price, in the currency this request resolved to, in
            // integer minor units. Never all three, and never converted.
            'price' => [
                'currency' => $currency,
                'amount' => $this->priceIn($currency),
            ],
        ];
    }
}
