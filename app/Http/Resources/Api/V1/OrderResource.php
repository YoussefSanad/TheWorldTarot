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

                // **What the buyer typed, echoed back, and never the code.**
                //
                // The address is the only part of a gift the buyer can check.
                // They never receive the code, and nothing on this side can
                // tell that a gift mail arrived nowhere, so a wrong address is
                // unrecoverable and this response is the one place it is ever
                // shown back to the person who typed it.
                //
                // **The code is deliberately absent, and not merely missing
                // yet.** It does not exist at placement, since minting one
                // before the money arrives leaves a live bearer credential
                // behind every abandoned checkout, and it does not appear here
                // afterwards either: the buyer is not the person who spends it.
                //
                // Null rather than omitted on a line nobody gifted, so the
                // frontend is never telling an absent key from an empty one.
                'gift' => $line->gift === null ? null : [
                    'recipient_email' => $line->gift->recipient_email,
                    'signature' => $line->gift->signature,
                    'message' => $line->gift->message,
                    'locale' => $line->gift->locale,
                ],
            ])->all(),

            // **How the customer pays for this, and how they come back to it.**
            //
            // Replaced `payment_url` on 25 August 2026. There is no URL to send
            // anybody to any more: checkout happens on the frontend's own page,
            // and what it needs is the address of this order rather than the
            // address of a payment. POST it to /api/v1/orders/{pay_token}/pay
            // and that answers with what to do next.
            //
            // Unguessable, because ids here are sequential integers. Holding
            // this is the whole of the authority to pay for the order, so it
            // belongs in a response that is never cached, which this one is not.
            'pay_token' => $this->resource->pay_token,

            // Deliberately absent: who it belongs to. The caller either is that
            // person or has just told us who they are, so echoing an email back
            // only creates a way to confirm one.
        ];
    }
}
