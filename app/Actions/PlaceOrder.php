<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Gift;
use App\Models\Order;
use App\Models\OrderLine;
use App\Purchasability\Basket;
use App\Purchasability\Line;
use App\Purchasability\Purchasability;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turning a validated basket into a record of what somebody bought.
 *
 * Everything here is captured at this moment and never read from the catalogue
 * again. Jennifer edits prices, and an order that recalculates itself reports a
 * total nobody was ever charged.
 */
class PlaceOrder
{
    /**
     * @param  array<int, array{product: string, quantity?: int, question?: string|null, gift?: array<string, mixed>|null}>  $lines
     */
    public function __invoke(?Customer $customer, string $currency, array $lines): Order
    {
        // **Null is a buyer nobody knows anything about yet**, which is what
        // Stripe's hosted page leaves us with until it settles: it collects
        // the email after the order exists. The basket is then a basket with
        // no owner, and the rules that need one say so themselves rather than
        // being skipped here. See
        // `docs/adr/0002-the-redirect-case-comes-back.md`.
        $basket = $customer instanceof Customer
            ? Basket::forCustomer($customer, $currency, $lines)
            : Basket::forGuest(null, $currency, $lines);

        // **The guard is here as well as in the form request, and that is not
        // belt and braces.** StoreOrderRequest is the right place for the rule,
        // since a customer naming a product that does not exist deserves a 422
        // with the field named rather than an exception.
        //
        // This action is the shared seam though, and phase B adds a second
        // caller in the payment path that will not route through that form
        // request. Reaching `$product->id` on null and writing null into a
        // NOT NULL integer column are both poor ways for the one action that
        // turns money into a record to fail, and neither says what went wrong.
        //
        // **What is not here any more is a second copy of the rules.** Both
        // callers ask one declaration, so a rule added to the 422 cannot be
        // forgotten here. Anything arriving has already been validated, so this
        // firing is a fault in the caller and says so.
        //
        // That includes the pass, which is the thing here worth taking money
        // for wrongly. It never expires, so it is the one product that cannot
        // be sold to the same person twice, and a second one delivers nothing
        // at all because grantViewingRoomAccess keeps the first date on
        // purpose. The rule follows fulfillment rather than the product key,
        // so this seam and MarkOrderPaid are asking one question.
        //
        // **The buyer is the customer this order is being placed for**, which
        // is the authority here rather than an address somebody typed. The
        // basket looks nothing up unless a rule about access asks it to.
        Purchasability::declared()->guard($basket);

        // Priced before anything is written, so an order is never briefly
        // stored with a total of nothing.
        $priced = collect($basket->lines())->map(fn (Line $line): array => [
            'product_id' => $line->product->id,
            'product_key' => $line->product->key,
            'unit_amount' => $line->product->priceIn($currency),
            'quantity' => $line->quantity,

            // **Null on a gift line, and it is a normaliser now rather than
            // the policy.** Both on one line is refused before this runs, by
            // `Rules::aQuestionOnlyOnALineNobodyGifted` since 3 September 2026,
            // so the only question reaching here beside a gift is a blank one:
            // a form posting `""` for a field nobody filled in. Storing that as
            // nothing rather than as an empty string is the whole of what is
            // left to do here. The person who will ask has not seen the present
            // yet, and asking is what redemption is for. The stopgap that wrote
            // a note in here was always documented as having an end date.
            'question' => $line->gift === null ? $line->question : null,
        ]);

        $total = $priced->sum(fn (array $line): int => $line['unit_amount'] * $line['quantity']);

        // One transaction, because an order without its lines is a charge for
        // nothing and lines without their order are unreachable. **The gifts go
        // inside it too**: a gift row surviving a rolled back order is a
        // present hanging off a line nobody was ever charged for.
        return DB::transaction(function () use ($customer, $currency, $total, $priced, $basket): Order {
            $order = Order::create([
                'customer_id' => $customer?->id,
                'status' => OrderStatus::Pending,
                'currency' => $currency,
                'total_amount' => $total,
            ]);

            $written = $order->lines()->createMany($priced->all());

            $this->recordTheGifts($basket, $written);

            // The gifts as well as the lines, because the response echoes the
            // address each present is going to and a lazy load here would be
            // one query per gifted line.
            return $order->load('lines.gift');
        });
    }

    /**
     * A row for each line somebody bought for another person.
     *
     * **Written at placement, with no code.** The buyer's four fields are typed
     * at checkout, before the money arrives, and have to survive until it does,
     * exactly as `order_lines.question` does and for the reason ADR 0004 gives
     * about it. The code is the half that cannot come this early: minted here
     * it would be a live bearer credential backed by a payment that may never
     * arrive, one per abandoned checkout. `MarkOrderPaid` mints it at
     * settlement, and until then the row is inert.
     *
     * **Paired by position, not by product.** `createMany` writes in the order
     * it is given and the priced lines came off `$basket->lines()` in that same
     * order, so index for index is the only pairing that holds when one basket
     * carries the same reading twice, once as a gift and once for the buyer.
     *
     * @param  Collection<int, OrderLine>  $written
     */
    private function recordTheGifts(Basket $basket, Collection $written): void
    {
        foreach ($basket->lines() as $position => $line) {
            if ($line->gift === null) {
                continue;
            }

            Gift::create([
                'order_line_id' => $written[$position]->id,
                'recipient_email' => $line->gift['recipient_email'],
                'signature' => $line->gift['signature'],
                'message' => $line->gift['message'] ?? null,

                // **Defaulted here as well as in the form request, the way the
                // guard above is.** This is the shared seam and nothing
                // reaching it can be assumed to have come through that request,
                // so a caller that never filled the language in would otherwise
                // write a gift with no language to render its mail in. The
                // address and the signature are deliberately not defaulted:
                // there is nothing to guess, and a caller that omitted them has
                // a fault the database says loudly.
                'locale' => $line->gift['locale'] ?? app()->getLocale(),
            ]);
        }
    }
}
