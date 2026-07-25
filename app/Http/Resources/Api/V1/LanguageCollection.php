<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * The contract in .claude/api.md is a bare array, so this collection drops
 * Laravel's data wrapper.
 *
 * Done here rather than with a global JsonResource::withoutWrapping(), which
 * would silently decide the shape of every endpoint added in later milestones.
 * A later endpoint that wants a wrapper should be free to have one.
 */
class LanguageCollection extends ResourceCollection
{
    public $collects = LanguageResource::class;

    public static $wrap = null;
}
