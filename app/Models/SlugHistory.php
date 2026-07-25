<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A slug a record used to have, in one locale.
 *
 * Write only for now. Redirects are built on this later. See
 * .claude/translation.md section 9.
 */
#[Fillable(['locale', 'slug'])]
class SlugHistory extends Model
{
    protected $table = 'slug_history';

    // Only ever created, never updated, so there is nothing for updated_at to
    // record.
    public const UPDATED_AT = null;

    public function sluggable(): MorphTo
    {
        return $this->morphTo();
    }
}
