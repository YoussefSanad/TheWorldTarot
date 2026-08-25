<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Something somebody bought.
 *
 * Holds what was charged rather than what things cost now. Everything about
 * money here is an integer in minor units, hard rule 5.
 */
#[Fillable(['customer_id', 'status', 'currency', 'total_amount'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total_amount' => 'integer',
            'charged_amount' => 'integer',
            'paid_at' => 'datetime',
            'fulfilled_at' => 'datetime',
        ];
    }

    /**
     * Every order gets the token that lets whoever placed it pay for it.
     *
     * Here rather than in PlaceOrder, because there is more than one way an
     * order comes into existence and an order without one cannot be paid for.
     * A single writer is what keeps that from depending on which path made it.
     */
    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->pay_token ??= self::mintPayToken();
        });
    }

    /**
     * An unguessable name for one order.
     *
     * **Not derived from anything about the order**, and that is the point.
     * Ids here are sequential integers, so an id in a URL is a number somebody
     * can count to, and the next one along belongs to another customer. This is
     * random, and it is the whole of the authority a guest has to pay.
     *
     * `Str::random` reads from random_bytes, so this is as unguessable as the
     * session cookie beside it.
     */
    public static function mintPayToken(): string
    {
        return Str::random(48);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function isPaid(): bool
    {
        return $this->status === OrderStatus::Paid;
    }

    /**
     * Placed and not collected for, which is the only state a payment may start
     * from.
     */
    public function isPending(): bool
    {
        return $this->status === OrderStatus::Pending;
    }

    public function isFulfilled(): bool
    {
        return $this->fulfilled_at !== null;
    }

    /**
     * What the lines add up to.
     *
     * The stored total is the authority on what was charged. This exists to
     * check it, and for whatever builds an order to set it, never as a
     * substitute for reading the column.
     */
    public function totalOfLines(): int
    {
        return $this->lines->sum(fn (OrderLine $line): int => $line->subtotal());
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Pending);
    }

    public function scopePaid(Builder $query): Builder
    {
        return $query->where('status', OrderStatus::Paid);
    }

    /**
     * Paid for and not yet delivered, which is Jennifer's working list.
     */
    public function scopeAwaitingFulfillment(Builder $query): Builder
    {
        return $query->paid()->whereNull('fulfilled_at');
    }
}
