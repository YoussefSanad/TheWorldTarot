<?php

namespace App\Models\Concerns;

use App\Models\Price;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Gives a model fixed prices in one or more currencies.
 *
 * Deliberately has no fallback. If a thing has no price in the requested
 * currency it is not for sale in that currency, and the caller decides what
 * that means. Returning another currency's amount, or converting one, would
 * put a wrong number in front of a customer. See .claude/database.md.
 */
trait HasPrices
{
    public function prices(): MorphMany
    {
        return $this->morphMany(Price::class, 'priceable');
    }

    public function priceIn(string $currencyCode): ?Price
    {
        return $this->prices
            ->firstWhere('currency_code', strtoupper($currencyCode));
    }

    /**
     * The amount in integer minor units, or null if this thing has no price in
     * that currency.
     */
    public function amountIn(string $currencyCode): ?int
    {
        return $this->priceIn($currencyCode)?->amount;
    }

    /**
     * Set or replace the price for one currency. Used by the admin panel from
     * M2 onward, where Jennifer types each currency's amount by hand.
     */
    public function setPriceIn(string $currencyCode, int $amount): Price
    {
        $price = $this->prices()->updateOrCreate(
            ['currency_code' => strtoupper($currencyCode)],
            ['amount' => $amount],
        );

        $this->unsetRelation('prices');

        return $price;
    }
}
