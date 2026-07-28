<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A bare array, matching the shape of /products and /languages.
 */
class CardCollection extends ResourceCollection
{
    public $collects = CardResource::class;

    public static $wrap = null;
}
