<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order, as the frontend sees it.
 *
 * Money is integer minor units here as it is everywhere, per .claude/api.md.
 * Formatting it is the frontend's job, and the currency is on the order so
 * there is never a number without one beside it.
 *
 * @property-read Order $resource
 */
class OrderResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  string|null  $paymentUrl  Where to send the customer to pay, or
     *                                   null when there is nowhere to send
     *                                   them and the order simply waits.
     */
    public function __construct($resource, private readonly ?string $paymentUrl = null)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'status' => $this->resource->status->value,
            'currency' => $this->resource->currency,
            'total_amount' => $this->resource->total_amount,

            'lines' => $this->resource->lines->map(fn (OrderLine $line): array => [
                'product' => $line->product_key->value,
                'unit_amount' => $line->unit_amount,
                'quantity' => $line->quantity,
                'question' => $line->question,
            ])->all(),

            // Null today, and null is not an error. Nothing is collected until
            // phase B, so an order waits for somebody to settle it.
            'payment_url' => $this->paymentUrl,

            // Deliberately absent: who it belongs to. The caller either is that
            // person or has just told us who they are, so echoing an email back
            // only creates a way to confirm one.
        ];
    }
}
