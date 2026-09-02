<?php

namespace App\Models;

use Database\Factories\ReadingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
