<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What somebody bought, and what it cost them at the time.
     *
     * Two tables rather than one because an order holds more than one thing.
     * The 24 hour rush is defined as an add-on "bought alongside something
     * else", and CLAUDE.md section 4 describes a currency carrying from product
     * page to cart to payment. Both mean a basket, so a single line order is a
     * special case of this rather than the shape to build.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();

            // Never null. Every order belongs to somebody, and a buyer with no
            // account gets one made from the email they gave, unclaimed until
            // they set a password. Decided 8 August 2026.
            //
            // Restricted rather than cascading: deleting a customer who has
            // bought something must fail loudly rather than quietly destroy the
            // record of a payment.
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();

            // Money state only. String column with a PHP enum cast.
            $table->string('status')->index();

            // The currency at the moment of purchase, and one for the whole
            // order, because a basket priced in two currencies has no total.
            $table->string('currency', 3);

            // Integer minor units, hard rule 5. The sum of the lines, stored
            // rather than derived, so what was charged survives any later
            // change to what things cost.
            $table->unsignedInteger('total_amount');

            // When the money arrived, and separately when the reading was
            // written and sent. Dates rather than more statuses, because a date
            // answers when as well as whether, which is the question support
            // actually gets asked.
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();

            $table->timestamps();
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();

            // Lines have no meaning without their order.
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();

            // One products table, referenced by key. That single argument is
            // what settled the schema on 25 July 2026, and this is the row it
            // was settled for. Restricted, and products soft delete, so a
            // purchase can never be orphaned by tidying the catalogue.
            $table->foreignId('product_id')->constrained()->restrictOnDelete();

            // Captured, not joined. The id says which row, this says what it
            // was, and it survives even that row being renamed.
            $table->string('product_key');

            // What one of them cost, at the moment of buying. **Never read from
            // the product again.** Jennifer edits prices, and an order that
            // recalculates itself from the catalogue reports a different total
            // after she does, which is wrong for support, wrong for accounting,
            // and invisible until it matters.
            $table->unsignedInteger('unit_amount');
            $table->unsignedInteger('quantity')->default(1);

            // What the customer wants the reading to answer.
            //
            // On the line rather than the order, because `allows_question` is a
            // property of a product and an order holding two readings holds two
            // questions. On the order, the second one is unanswerable.
            $table->text('question')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
        Schema::dropIfExists('orders');
    }
};
