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

            // What lets the site choose between "enter the Viewing Room" and
            // "buy the pass" without guessing.
            //
            // **Telling the frontend is not the same as trusting it.** The
            // decision is made again on the server every time a film is asked
            // for, and this field only spares a customer being shown a door
            // that will not open. Per .claude/api.md: never rely on the
            // frontend not rendering a link.
            'has_viewing_room_access' => $this->resource->hasViewingRoomAccess(),

            'token' => $this->whenNotNull($this->token),
        ];
    }
}
