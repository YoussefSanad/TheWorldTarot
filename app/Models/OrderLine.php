<?php

namespace App\Models;

use App\Enums\ProductKey;
use Database\Factories\OrderLineFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One thing on an order, at the price it was that day.
 */
#[Fillable(['product_id', 'product_key', 'unit_amount', 'quantity', 'question'])]
class OrderLine extends Model
{
    /** @use HasFactory<OrderLineFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'product_key' => ProductKey::class,
            'unit_amount' => 'integer',
            'quantity' => 'integer',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The product this was, including one that has since been retired.
     *
     * **`withTrashed` is load bearing.** Products soft delete precisely so an
     * order can go on referring to them, and without this a line for a retired
     * product resolves to null, so an old order in the panel shows a purchase
     * of nothing.
     *
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /**
     * What the customer saw this called, on every screen and in every message.
     *
     * **Here because twelve call sites said it three different ways.** Seven
     * fell back with `??`, four with `?:`, and one reached for the catalogue's
     * default, so the chains disagreed about what to fall back to as well as
     * about when. `product_key->value` is `three-card` and
     * `ProductKey::defaultName()` is `3 CARD READING`: the same product, named
     * two different ways to the same customer on two different screens.
     *
     * **Emptiness and not null is what the check has to be, and that was the
     * defect.** `Product::$name` is translatable, and
     * `spatie/laravel-translatable` answers the empty string rather than null
     * when the read locale and the `en` fallback are both blank. So a null
     * check never fired: a product whose name was blank was named in the panel
     * and nameless in the customer's mail. Blank rather than empty, because a
     * name somebody has typed a space into renders as the same missing word.
     *
     * **The catalogue's default and never the key.** `3 CARD READING` is copy
     * off the homepage tile that the client signed off. `three-card` is a URL
     * slug, and it reached a customer's inbox by accident. Decided 4 September
     * 2026.
     *
     * **Two things reach the fallback and only one of them can happen.** A
     * blank name is the real one, and it is what this method exists for. A
     * product row that is gone is the defensive one: products soft delete
     * precisely so an order can go on naming them, `product()` above carries
     * `withTrashed()` for it, and `order_lines.product_id` is NOT NULL behind
     * `restrictOnDelete`, so a retired reading never gets here and nothing else
     * can either. It is kept because a code for a withdrawn product still
     * redeems, and a row that could not say what was owed would be a liability
     * nobody can read.
     *
     * **The two gift endpoints deliberately do not use this**, and are the only
     * places left reaching for the product's own name. `GiftLookupResource` and
     * `RedeemedGiftResource` cast the raw translatable value on purpose, and
     * changing that changes the API contract with the frontend partner. Their
     * own docblocks carry the reason. Decided alongside the rule above and
     * left as it was.
     *
     * The captured `product_key` stays the record of what was bought. This
     * reads it and never writes it.
     */
    public function displayName(): string
    {
        $name = $this->product?->name;

        return filled($name) ? (string) $name : $this->product_key->defaultName();
    }

    /**
     * What has been asked off this line.
     *
     * **Not one.** `readings.order_line_id` is deliberately not unique: a line
     * is one product at one price and nothing promises it is asked about
     * exactly once.
     *
     * @return HasMany<Reading, $this>
     */
    public function readings(): HasMany
    {
        return $this->hasMany(Reading::class);
    }

    /**
     * The present this line was bought as, if it was bought as one.
     *
     * **Null is a line somebody bought for themselves**, which is most of them.
     * One line is one thing bought once, so there is at most one, and the
     * unique index on `gifts.order_line_id` is what holds that rather than this
     * relation.
     *
     * @return HasOne<Gift, $this>
     */
    public function gift(): HasOne
    {
        return $this->hasOne(Gift::class);
    }

    /**
     * What this line cost. Integer minor units, never recalculated from the
     * catalogue.
     */
    public function subtotal(): int
    {
        return $this->unit_amount * $this->quantity;
    }
}
