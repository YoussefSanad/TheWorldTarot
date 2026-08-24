<?php

namespace App\Models;

use App\Availability\Availability;
use App\Availability\Condition;
use App\Availability\Conditions;
use App\Availability\UnpriceableCurrency;
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
use Illuminate\Support\Facades\Schema;
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
     * Price columns worked out per currency list, keyed by that list.
     *
     * @var array<string, array<int, string>>
     */
    private static array $priceColumns = [];

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
     * What a product needs before it is on the website in a language.
     *
     * The same treatment cards have, with the one condition they do not: a real
     * price in every currency this system sells in.
     *
     * Availability is worked out, not switched on. A reading is on the website
     * when it has its copy in that language and a real price in every currency,
     * so there is nothing to remember to tick and nothing to leave in the wrong
     * position by accident.
     *
     * @see Card::availability() for what the two share
     */
    public static function availability(): Availability
    {
        return Availability::of(
            Conditions::finishedInTheOriginalLanguage(),
            Conditions::finishedInTheLanguageAsked(),
            self::pricedInEveryCurrency(),
            Conditions::published(),
            Conditions::notRemoved(),
        );
    }

    /**
     * Every price set to something real, declared once for both renderings.
     *
     * A seeded reading starts at zero in every currency, which the database
     * accepts: `NOT NULL` can insist a price exists, not that it means
     * anything. Nothing is ever sold for nothing.
     */
    private static function pricedInEveryCurrency(): Condition
    {
        return Condition::make(
            missing: 'a price in every currency',
            query: function (Builder $query): void {
                foreach (self::priceColumns() as $column) {
                    $query->where($column, '>', 0);
                }
            },
            record: function (self $product): bool {
                foreach (self::priceColumns() as $column) {
                    if ((int) ($product->getAttribute($column) ?? 0) <= 0) {
                        return false;
                    }
                }

                return true;
            },
        );
    }

    /**
     * The column holding each currency's price, refusing a currency the table
     * cannot price.
     *
     * Read once per currency list rather than per query, since the answer can
     * only change with a deploy.
     *
     * @return array<int, string>
     */
    private static function priceColumns(): array
    {
        $currencies = array_keys(config('currencies.available'));
        $key = implode(',', $currencies);

        return self::$priceColumns[$key] ??= array_map(
            function (string $code): string {
                $column = 'price_'.strtolower($code);

                if (! Schema::hasColumn('products', $column)) {
                    throw UnpriceableCurrency::for($code, 'products', $column);
                }

                return $column;
            },
            $currencies,
        );
    }

    /**
     * The pricing rule on its own, kept for the callers that only want that
     * question. Both are renderings of the one condition above rather than the
     * two implementations they were, so a currency cannot be counted by one and
     * not the other.
     *
     * The locale is passed because a condition takes one, and ignored because
     * a price is the same number in every language.
     */
    public function scopePriced(Builder $query): Builder
    {
        self::pricedInEveryCurrency()->constrain($query, Locales::default());

        return $query;
    }

    public function isPriced(): bool
    {
        return self::pricedInEveryCurrency()->isMetBy($this, Locales::default());
    }

    /**
     * What a customer reading the site in this language can actually see.
     */
    public function scopeAvailableIn(Builder $query, string $locale): Builder
    {
        return static::availability()->constrain($query, $locale);
    }

    public function isAvailableIn(string $locale): bool
    {
        return static::availability()->satisfiedBy($this, $locale);
    }

    /**
     * Why a reading is not on the website yet, in the client's words. Empty
     * when it is.
     *
     * @return array<int, string>
     */
    public function whatIsMissing(string $locale): array
    {
        return static::availability()->unmetBy($this, $locale);
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
