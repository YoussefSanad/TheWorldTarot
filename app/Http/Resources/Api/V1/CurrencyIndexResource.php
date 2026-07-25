<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The whole /currencies payload: what can be sold in, and what this particular
 * request resolved to.
 *
 * `detected` is a per-request value, so it is deliberately part of the same
 * response rather than a separate endpoint the frontend would have to combine.
 */
class CurrencyIndexResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'available' => CurrencyResource::collection($this->resource['available']),
            'detected' => $this->resource['detected'],
        ];
    }
}
