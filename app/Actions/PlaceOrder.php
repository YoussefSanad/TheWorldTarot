<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Purchasability\Basket;
use App\Purchasability\Line;
use App\Purchasability\Purchasability;
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
     * @param  array<int, array{product: string, quantity?: int, question?: string|null}>  $lines
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
            'question' => $line->question,
        ]);

        $total = $priced->sum(fn (array $line): int => $line['unit_amount'] * $line['quantity']);

        // One transaction, because an order without its lines is a charge for
        // nothing and lines without their order are unreachable.
        return DB::transaction(function () use ($customer, $currency, $total, $priced): Order {
            $order = Order::create([
                'customer_id' => $customer?->id,
                'status' => OrderStatus::Pending,
                'currency' => $currency,
                'total_amount' => $total,
            ]);

            $order->lines()->createMany($priced->all());

            return $order->load('lines');
        });
    }
}
