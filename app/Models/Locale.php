<?php

namespace App\Models;

use App\Enums\LocaleState;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['code', 'state', 'sort_order'])]
class Locale extends Model
{
    protected function casts(): array
    {
        return [
            'state' => LocaleState::class,
        ];
    }

    /**
     * Only locales whose code is also declared in config are real. Config always
     * wins over the database, so a row for an undeclared code is filtered out.
     */
    public function scopeConfigured(Builder $query): Builder
    {
        return $query->whereIn('code', array_keys(config('localization.available')));
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->configured()->where('state', LocaleState::Live);
    }

    /**
     * Whether this locale's code is declared in config/localization.php. A locale
     * that is not configured does not exist, regardless of its stored state.
     */
    public function isConfigured(): bool
    {
        return array_key_exists($this->code, config('localization.available'));
    }
}
