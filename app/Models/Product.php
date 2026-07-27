<?php

namespace App\Models;

use App\Enums\Fulfilment;
use App\Enums\ProductKey;
use App\Enums\ProductType;
use App\Support\CurrencyResolver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Translatable\HasTranslations;

/**
 * Anything sellable: readings, the viewing room pass, add-ons.
 *
 * Prices are columns rather than a related table, one per currency, all of them
 * required by the database. Nothing here converts between currencies, and a
 * price is never derived from another price. See .claude/database.md.
 */
#[Fillable([
    'key', 'type', 'fulfilment', 'allows_question', 'name',
    'short_description', 'long_description', 'price_usd', 'price_eur',
    'price_gbp', 'is_published', 'sort_order',
])]
class Product extends Model
{
    use HasFactory, HasTranslations, SoftDeletes;

    /**
     * @var array<int, string>
     */
    public array $translatable = ['name', 'short_description', 'long_description'];

    protected function casts(): array
    {
        return [
            'key' => ProductKey::class,
            'type' => ProductType::class,
            'fulfilment' => Fulfilment::class,
            'allows_question' => 'boolean',
            'is_published' => 'boolean',
            'price_usd' => 'integer',
            'price_eur' => 'integer',
            'price_gbp' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function scopeOfType(Builder $query, ProductType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * The price in one currency, in integer minor units.
     *
     * Returns null for a currency this system does not sell in, rather than
     * falling back to another currency. Showing a customer a number from the
     * wrong currency is worse than showing none.
     */
    public function priceIn(string $currencyCode): ?int
    {
        // CurrencyResolver owns the definition of what we sell in, so this
        // cannot drift from the rest of the system.
        if (! CurrencyResolver::isSupported($currencyCode)) {
            return null;
        }

        return $this->getAttribute('price_'.strtolower($currencyCode));
    }
}
