<?php

namespace App\Actions;

use App\Enums\Fulfillment;
use App\Enums\OrderStatus;
use App\Models\Customer;
use App\Models\Order;
use App\Purchasability\Basket;
use App\Purchasability\Line;
use App\Purchasability\Purchasability;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

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
    public function __invoke(Customer $customer, string $currency, array $lines): Order
    {
        $basket = Basket::of($currency, $lines);

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

        // **The pass never expires, so it cannot be sold to the same person
        // twice.** StoreOrderRequest refuses this with a 422 naming the line,
        // which is what a customer can be shown. The guard is here as well for
        // the reason the declaration above is: this action is the shared seam,
        // and phase B adds a payment path that does not route through that form
        // request. What it prevents is the worst version of the failure, money
        // taken for something the buyer already holds forever, which
        // grantViewingRoomAccess will decline to deliver twice.
        //
        // Still stated here rather than in the declaration, deliberately. These
        // join it on their own, in the ticket that follows.
        $granting = collect($basket->lines())->filter(
            fn (Line $line): bool => $line->product->fulfillment === Fulfillment::AccessGrant
        );

        if ($granting->isNotEmpty()) {
            $key = $granting->first()->key;

            if ($customer->hasViewingRoomAccess()) {
                throw new InvalidArgumentException(
                    "Customer [{$customer->id}] already holds the access [{$key}] grants."
                );
            }

            if ($granting->sum(fn (Line $line): int => $line->quantity) > 1) {
                throw new InvalidArgumentException(
                    "Cannot place an order for more than one [{$key}]."
                );
            }
        }

        $total = $priced->sum(fn (array $line): int => $line['unit_amount'] * $line['quantity']);

        // One transaction, because an order without its lines is a charge for
        // nothing and lines without their order are unreachable.
        return DB::transaction(function () use ($customer, $currency, $total, $priced): Order {
            $order = Order::create([
                'customer_id' => $customer->id,
                'status' => OrderStatus::Pending,
                'currency' => $currency,
                'total_amount' => $total,
            ]);

            $order->lines()->createMany($priced->all());

            return $order->load('lines');
        });
    }
}
