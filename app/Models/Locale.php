<?php

namespace App\Models;

use App\Enums\LocaleStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A language the site can be read in.
 *
 * This table is the whole registry. There is no config file listing which
 * locales may exist, which is what makes turning a language off a status change
 * taking effect on the next request rather than a deploy.
 *
 * See .claude/translation.md section 3.
 */
#[Fillable(['code', 'english_name', 'native_name', 'status', 'sort_order'])]
class Locale extends Model
{
    protected function casts(): array
    {
        return [
            'status' => LocaleStatus::class,
        ];
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('code');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->where('status', LocaleStatus::Live);
    }

    public function isLive(): bool
    {
        return $this->status === LocaleStatus::Live;
    }

    /**
     * Whether this language may be served to the visitor making the request.
     *
     * Live is public. Preview is for a signed-in admin only, which is how the
     * whole site gets reviewed in a language before customers can reach it.
     * Draft is nobody, admins included: a half translated site is worse than an
     * English one.
     */
    public function isServableTo(bool $isAdmin): bool
    {
        return match ($this->status) {
            LocaleStatus::Live => true,
            LocaleStatus::Preview => $isAdmin,
            LocaleStatus::Draft => false,
        };
    }
}
