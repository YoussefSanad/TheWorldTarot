<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Locale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Locale
 */
class LanguageResource extends JsonResource
{
    /**
     * The frontend contract in .claude/api.md is a bare array, so this resource
     * is not wrapped in a data key. Changing that is a coordination event with
     * the frontend partner, not a unilateral change.
     */
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'code' => $this->code,

            'label' => $this->english_name,

            // There is deliberately no `enabled` flag. Everything this endpoint
            // returns is live, so the flag would always be true and would imply
            // the list can contain languages that are not yet public.
        ];
    }
}
