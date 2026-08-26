<?php

namespace App\Http\Resources\Api\V1;

use App\Payments\PaymentInstruction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What the customer does next to pay, as the frontend sees it.
 *
 * **Unwrapped, like every other resource here.** `$wrap = null` means the body
 * is byte for byte what the controller returned before this class existed, so
 * this is a contract-shape change and not a contract change. Section 9 of
 * API_CONTRACT.md is unaffected.
 *
 * Why wrap a value object that already knows how to render itself. Because
 * .claude/api.md says every endpoint answers with a resource, and the frontend
 * partner parses this API one way. "This one endpoint is special" is a seam that
 * costs them and buys us nothing: the argument for keeping it raw was that a
 * client secret is a credential rather than a representation of a thing, which
 * is true and is answered by `Cache-Control: no-store` on the response, not by
 * the class that shaped the body.
 *
 * **No order state reaches this**, and that is load-bearing rather than
 * incidental. The pay token is the whole authority on this route, so a guessed
 * one must leak no amount and no email. `PaymentInstruction` carries none, and
 * this resource must never be given a model to reach for.
 *
 * @property-read PaymentInstruction $resource
 */
class PaymentInstructionResource extends JsonResource
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
