<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Customer;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A signed in customer, as the frontend sees them.
 *
 * A resource rather than the model, per .claude/api.md, which here is not only
 * convention: returning the model would put the password hash and the remember
 * token one `$hidden` mistake away from the wire.
 *
 * @property-read Customer $resource
 */
class CustomerResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  string|null  $token  Present only when a native app signed in and
     *                              asked for one. The website uses a cookie and
     *                              never sees this field.
     */
    public function __construct($resource, private readonly ?string $token = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'name' => $this->resource->name,
            'email' => $this->resource->email,
            'created_at' => $this->resource->created_at?->toIso8601String(),

            // Whether they hold the Viewing Room pass is deliberately absent
            // until step 4 adds it. It is an authorization answer, and it
            // arrives with the policy that owns that answer.
            'token' => $this->whenNotNull($this->token),
        ];
    }
}
