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
