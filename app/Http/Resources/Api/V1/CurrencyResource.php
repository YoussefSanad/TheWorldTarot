<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One currency the system sells in. The resource is built from config, since
 * currencies are a deploy-coupled list rather than database state.
 */
class CurrencyResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->resource['code'],
            'symbol' => $this->resource['symbol'],
        ];
    }
}
