<?php

namespace App\Models;

use App\Enums\Fulfillment;
use App\Enums\ProductKey;
use App\Enums\ProductType;
use App\Models\Concerns\HasTranslationCompleteness;
use App\Support\CurrencyResolver;
use App\Support\Locales;
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
    'key', 'type', 'fulfillment', 'allows_question', 'name',
    'short_description', 'long_description', 'price_usd', 'price_eur',
    'price_gbp', 'is_published', 'sort_order', 'defaults_seeded_at',
])]
class Product extends Model
{
    use HasFactory, HasTranslationCompleteness, HasTranslations, SoftDeletes;

    /**
     * @var array<int, string>
     */
    public array $translatable = ['name', 'short_description', 'long_description'];

    /**
     * What a language needs before this product can be shown in it, and what
     * English needs before it can be published at all.
     *
     * The same list drives both, so the two can never disagree about what
     * "finished" means.
     *
     * @return array<int, string>
     */
    public static function requiredTranslations(): array
    {
        return ['name', 'short_description', 'long_description'];
    }

    protected function casts(): array
    {
        return [
            'key' => ProductKey::class,
            'type' => ProductType::class,
            'fulfillment' => Fulfillment::class,
            'allows_question' => 'boolean',
            'is_published' => 'boolean',
            'price_usd' => 'integer',
            'price_eur' => 'integer',
            'price_gbp' => 'integer',
            'sort_order' => 'integer',
            'defaults_seeded_at' => 'datetime',
        ];
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    /**
     * Every price set to something real.
     *
     * A seeded reading starts at zero in every currency, which the database
     * accepts: `NOT NULL` can insist a price exists, not that it means
     * anything. Nothing is ever sold for nothing.
     */
    public function scopePriced(Builder $query): Builder
    {
        foreach (array_keys(config('currencies.available')) as $code) {
            $query->where('price_'.strtolower($code), '>', 0);
        }

        return $query;
    }

    public function isPriced(): bool
    {
        return collect(array_keys(config('currencies.available')))
            ->every(fn (string $code): bool => ($this->priceIn($code) ?? 0) > 0);
    }

    /**
     * What a customer reading the site in this language can actually see.
     *
     * Availability is worked out, not switched on. A reading is on the website
     * when it has its copy in that language and a real price in every currency,
     * so there is nothing to remember to tick and nothing to leave in the wrong
     * position by accident.
     */
    public function scopeAvailableIn(Builder $query, string $locale): Builder
    {
        return $query->published()
            ->priced()
            // The original language is the source. A reading that is not
            // finished in it is not finished at all, so it appears nowhere,
            // rather than existing in Spanish and not in English.
            ->completeIn(Locales::default())
            ->completeIn($locale);
    }

    public function isAvailableIn(string $locale): bool
    {
        return $this->is_published
            && $this->isPriced()
            && $this->isCompleteIn(Locales::default())
            && $this->isCompleteIn($locale);
    }

    /**
     * Why a reading is not on the website yet, in the client's words. Empty
     * when it is.
     *
     * @return array<int, string>
     */
    public function whatIsMissing(string $locale): array
    {
        $missing = $this->missingCopy($locale);

        if (! $this->isPriced()) {
            $missing[] = 'a price in every currency';
        }

        return $missing;
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
