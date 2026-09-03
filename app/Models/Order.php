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
use Illuminate\Support\Collection;
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

    /**
     * Every present on this order, in line order.
     *
     * **Written once because it was being written four times.** B6 (#72) added
     * two walks to `MarkOrderPaid`, one to mint and one to mail, and two more
     * closures to `OrderPaid`, all of them `foreach` over the lines asking
     * whether each carried a gift. Four copies of one predicate is four places
     * to be wrong about what a present is on the day one line can hold two, or
     * a gift can exist without a line.
     *
     * **Not a `HasManyThrough`.** It would be the same rows and would look
     * tidier, and it would also be a fifth definition living in a relation that
     * `everythingItOwesHasGone` below does not use, because that one has the
     * lines in hand already. This walks what is loaded.
     *
     * @return Collection<int, Gift>
     */
    public function gifts(): Collection
    {
        $this->loadMissing('lines.gift');

        return $this->lines
            ->map(fn (OrderLine $line): ?Gift => $line->gift)
            ->filter()
            ->values();
    }

    /**
     * Whether everything this order promised has gone.
     *
     * **The definition of fulfilled, asked rather than remembered.** ADR 0004
     * and `CONTEXT.md` both put it the same way: every reading delivered, every
     * gift redeemed and delivered. `MarkOrderFulfilled` owns what that is
     * recorded as and deliberately does not decide it, so this is where the
     * deciding lives for the panel's caller.
     *
     * **The gift half is not decoration.** An order holding a gift nobody has
     * redeemed is not delivered, because it plainly is not, and without this
     * clause delivering the self purchase beside it would close the order while
     * a present is still outstanding. A redeemed gift has a reading of its own,
     * which the first clause then covers.
     *
     * A line that owes nothing, a pass, is delivered by the money landing and
     * has no reading here at all, so it does not hold anything open. That is
     * `MarkOrderPaid`'s question and it is answered there.
     */
    public function everythingItOwesHasGone(): bool
    {
        $this->loadMissing('lines.readings', 'lines.gift');

        foreach ($this->lines as $line) {
            if ($line->gift !== null && ! $line->gift->isRedeemed()) {
                return false;
            }

            foreach ($line->readings as $reading) {
                if (! $reading->isDelivered()) {
                    return false;
                }
            }
        }

        return true;
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
     * Paid for and not yet delivered.
     *
     * **~~Jennifer's working list.~~ Struck 3 September 2026**, building B4
     * (#70). It is still a true question about orders and it is no longer the
     * queue anybody works from: that is readings that have not been delivered,
     * on `ReadingResource`, and the navigation badge went with it.
     *
     * **The distinction is the whole of ADR 0006's argument.** Left as the work
     * queue this would have needed a clause about gifts, because an order
     * holding one is paid and owes nothing anybody can do yet, and
     * `MarkOrderPaid::needsAPerson` would have needed the matching clause. The
     * two would then have had to agree forever by hand. Moving the queue is
     * what means nobody has to remember a third such pair.
     *
     * It has no production caller now. Kept because it says something true and
     * `MarkOrderPaidTest` asks it, and named as no longer the queue so nobody
     * builds a screen on it again.
     */
    public function scopeAwaitingFulfillment(Builder $query): Builder
    {
        return $query->paid()->whereNull('fulfilled_at');
    }
}
