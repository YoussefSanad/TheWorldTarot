<?php

use App\Models\Order;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a card payment leaves behind on an order.
     *
     * Three columns, all nullable, and the nullability is the decision worth
     * writing down. A hand settled order has no charge and no intent, and that
     * stays true after launch, so `NOT NULL DEFAULT 0` would have every such
     * order silently claiming a real charge of nothing. A number nobody
     * collected is worse than no number.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // What Stripe actually collected, integer minor units, hard rule 5.
            //
            // Stripe Tax is off, so this always equals `total_amount` and any
            // difference is a fault to detect rather than a case to handle.
            // Recorded anyway, because a support conversation and a bank
            // statement have to agree about a number that came from the bank.
            $table->unsignedInteger('charged_amount')->nullable()->after('total_amount');

            // The payment being collected for this order, so a payment Stripe
            // accepted is reconcilable even if no notification ever arrives.
            // Written back at the moment the intent is created rather than when
            // it succeeds, since a lost webhook is exactly the case this exists
            // for and by then there is nothing to write it from.
            //
            // Indexed because the sweep iterates on it.
            //
            // **A second attempt overwrites it with the newest intent.** Stated
            // rather than discovered: the sweep then only knows about the
            // newest one, and an older intent that somehow succeeded is found
            // through the notification's metadata.order_id instead. Both paths
            // reach MarkOrderPaid, which refuses the second transition.
            $table->string('stripe_payment_intent_id')->nullable()->index()->after('charged_amount');

            // How a guest proves an order is theirs.
            //
            // Order ids are sequential integers, so an id in a path is a number
            // anybody can guess and the next one along belongs to somebody
            // else. This is unguessable, and holding it is the whole of the
            // authority to pay for an order.
            $table->string('pay_token')->nullable()->unique()->after('stripe_payment_intent_id');
        });

        // Every order that already exists gets one, so nothing placed before
        // today is unpayable. Both deployed environments hold disposable data,
        // confirmed 25 August 2026, so this is a handful of rows.
        Order::withoutEvents(function (): void {
            foreach (DB::table('orders')->whereNull('pay_token')->pluck('id') as $id) {
                DB::table('orders')->where('id', $id)->update(['pay_token' => Order::mintPayToken()]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['stripe_payment_intent_id']);
            $table->dropUnique(['pay_token']);
            $table->dropColumn(['charged_amount', 'stripe_payment_intent_id', 'pay_token']);
        });
    }
};
