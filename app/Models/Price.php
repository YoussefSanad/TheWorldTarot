<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A fixed price for one thing in one currency, stored in integer minor units.
 *
 * There is no conversion anywhere in this class. A missing currency is missing,
 * not derived from another currency at a rate. See .claude/database.md.
 */
#[Fillable(['currency_code', 'amount'])]
class Price extends Model
{
    protected function casts(): array
    {
        return [
            // Postgres returns integers as strings through some drivers. The
            // cast is what guarantees callers get an int and never a numeric
            // string that would silently become a float in arithmetic.
            'amount' => 'integer',
        ];
    }

    public function priceable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Currency codes are stored uppercase so lookups are exact. Normalising on
     * write rather than on read keeps the unique constraint meaningful.
     */
    protected function currencyCode(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => strtoupper($value),
        );
    }
}
