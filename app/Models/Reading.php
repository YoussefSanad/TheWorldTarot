<?php

namespace App\Models;

use App\Enums\Due;
use App\Enums\ProductKey;
use Carbon\CarbonInterface;
use Database\Factories\ReadingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Somebody asked a question, and is waiting for the answer.
 *
 * **It exists from the moment somebody asks, and never before.** That is the
 * whole of `docs/adr/0004-a-reading-is-a-row-of-its-own.md`: an order says money
 * arrived, and until this row there was nothing that said a person is waiting.
 * A gift makes those two different days.
 *
 * Two moments create one and they are the same moment in different clothes.
 * Settlement, for a line nobody gifted, and redemption, for a line somebody
 * did. `AskForReading` is the only writer of either.
 */
#[Fillable(['order_line_id', 'gift_id', 'question', 'querent_email', 'querent_name', 'asked_at'])]
class Reading extends Model
{
    /** @use HasFactory<ReadingFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'asked_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * What was bought, at the price it was that day.
     *
     * @return BelongsTo<OrderLine, $this>
     */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class);
    }

    /**
     * When this was promised, which is `asked_at` plus what the product sells.
     *
     * **Computed here and never stored.** A `due_at` column is a second writer
     * for a value that already has one, and it goes stale the day a delivery
     * window changes. That is the fault `MarkOrderFulfilled` exists to prevent
     * for `fulfilled_at`, and ADR 0006 refuses to re-introduce it one screen
     * along.
     *
     * **Null is "nothing is delivered", not "unknown"**, which is the meaning
     * `ProductKey::deliveryWindow()` already gives it. A pass grants access the
     * moment it is paid for and an add-on changes another line's wait, so
     * neither has a window. Neither has a reading either, so this is defensive
     * rather than reachable, and it returns null instead of guessing.
     *
     * The delivery window is the first promise this system ever made in a
     * customer's inbox. This is the first time the person who has to keep it
     * can see it.
     */
    public function dueAt(): ?CarbonInterface
    {
        $window = $this->orderLine->product_key->deliveryWindow();

        return $window === null ? null : $this->asked_at->copy()->add($window);
    }

    /**
     * Where this stands against the promise, as one of four named states.
     *
     * **The only place the four are enumerated.** Every surface that shows due
     * asks this rather than branching on `isOverdue()` and a null date for
     * itself, which is how the list came to know three states and the detail
     * page two. `Due` carries the argument for the set; this carries the sum.
     *
     * **Delivered is asked first and wins.** A reading that went out two days
     * late is delivered, not overdue, for the reason `isOverdue()` gives below.
     *
     * Computed at read time and never stored, like everything else here.
     */
    public function due(): Due
    {
        if ($this->isDelivered()) {
            return Due::Delivered;
        }

        $due = $this->dueAt();

        if ($due === null) {
            return Due::NoWindow;
        }

        return $due->isPast() ? Due::Overdue : Due::Waiting;
    }

    /**
     * Whether the promise has been missed and the reading is still owed.
     *
     * **A delivered reading is never overdue, however long it took.** This is a
     * question about work outstanding, not a grade on work already done, and
     * colouring a finished row red would put permanent noise on the one screen
     * that has to stay readable.
     *
     * **Kept as the sentence it is, and answered by `due()`.** The panel
     * stopped asking it, because a screen showing due has four answers to give
     * and this has two, and answering the wrong one of those questions is what
     * `Due` exists to have stopped. It stays because the rule above is worth a
     * name, and because it is the form the model's own tests state it in.
     *
     * No job asks this and nothing alerts on it. The screen is where she looks.
     */
    public function isOverdue(): bool
    {
        return $this->due() === Due::Overdue;
    }

    /**
     * Oldest promise first, or last.
     *
     * **Due has to be computed in SQL as well as in PHP, and the two are one
     * definition split by necessity.** `dueAt()` answers for a row in hand;
     * this answers for a page of rows the database has not sent yet, and
     * sorting a paginated table in PHP would sort only the page. The `case` is
     * built from `ProductKey::deliveryWindow()` rather than written out, so a
     * window corrected in the catalogue moves both.
     *
     * **Not `asked_at`, which is the tempting shortcut and is wrong.** The four
     * readings do not share a window: `in-depth` promises 48 hours and the rest
     * promise 24, so a reading asked an hour earlier can be due a day later.
     * Sorting by when it was asked would quietly be a different list.
     *
     * @param  Builder<Reading>  $query
     * @return Builder<Reading>
     */
    public function scopeOrderByDue(Builder $query, string $direction = 'asc'): Builder
    {
        $cases = [];
        $bindings = [];

        foreach (ProductKey::cases() as $key) {
            $window = $key->deliveryWindow();

            // Null is "nothing is delivered" rather than "unknown", so the row
            // has no due date rather than an unbounded one. Nothing with a null
            // window has a reading, so this is unreachable rather than lenient.
            if ($window === null) {
                continue;
            }

            $cases[] = 'when order_lines.product_key = ? then ?::interval';
            $bindings[] = $key->value;
            $bindings[] = $window;
        }

        return $query
            ->select('readings.*')
            ->join('order_lines', 'order_lines.id', '=', 'readings.order_line_id')
            ->orderByRaw(
                'readings.asked_at + (case '.implode(' ', $cases).' else null end) '
                    .($direction === 'desc' ? 'desc' : 'asc'),
                $bindings,
            );
    }

    /**
     * Whether somebody was given this rather than buying it.
     *
     * The querent of a gift never chose the reading they are holding, and has
     * never heard from us before. The list marks them for that reason.
     */
    public function isAGift(): bool
    {
        return $this->gift_id !== null;
    }

    /**
     * The present this came from, if it came from one.
     *
     * @return BelongsTo<Gift, $this>
     */
    public function gift(): BelongsTo
    {
        return $this->belongsTo(Gift::class);
    }

    /**
     * Whether this one has gone.
     *
     * **`delivered` is a reading's word and `fulfilled` is an order's.** An
     * order is fulfilled when everything it promised has been delivered, which
     * is `MarkOrderFulfilled`'s to say and not this model's.
     */
    public function isDelivered(): bool
    {
        return $this->delivered_at !== null;
    }
}
