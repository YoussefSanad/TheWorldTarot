<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * A bare array, matching the shape of /languages. Done here rather than with a
 * global JsonResource::withoutWrapping(), so a later endpoint that wants an
 * envelope is free to have one.
 */
class ProductCollection extends ResourceCollection
{
    public $collects = ProductResource::class;

    public static $wrap = null;
}
