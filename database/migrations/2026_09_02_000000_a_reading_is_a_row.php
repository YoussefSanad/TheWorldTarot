<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Somebody asked for a reading, which until now had only ever been a
     * payment.
     *
     * `docs/adr/0004-a-reading-is-a-row-of-its-own.md`. "The money arrived" and
     * "somebody asked" have always happened at once here, and a gift bought in
     * September and redeemed in December pulls them three months apart. One row
     * cannot hold "paid, and not yet asked", so this is the row that can.
     *
     * **No backfill.** Nothing has launched, there is no live website and there
     * are no customers, so the rows a backfill would have carried do not exist.
     * This creates a table and nothing else, and every order in a deployed
     * environment is test data. Struck from the ADR's consequences on
     * 1 September 2026 for that reason.
     */
    public function up(): void
    {
        Schema::create('readings', function (Blueprint $table) {
            $table->id();

            // What was bought, at the price it was that day. Restricted, and
            // the line's own product reference is restricted for the same
            // reason: deleting the record of a purchase must fail loudly rather
            // than quietly orphan the work that was bought.
            //
            // Not unique. A line is one product at one price, and nothing here
            // promises it is asked about exactly once.
            $table->foreignId('order_line_id')->constrained()->restrictOnDelete();

            // Where it came from. Null on a self-purchase, and null on every
            // row until B5, which is the unit that creates `gifts` and the
            // constraint that points at it. **Nothing writes this column yet**,
            // and it is here rather than added later so that the shape being
            // proved is the whole shape.
            $table->unsignedBigInteger('gift_id')->nullable();

            // The question of record, and not `order_lines.question`, which is
            // what a buyer typed at checkout and exists because a question is
            // typed before the money arrives. Settlement copies the one onto
            // the other, the way a line captures `product_key` rather than
            // joining for it.
            //
            // **Nullable, and ADR 0004 said NOT NULL.** Struck 2 September 2026,
            // building B3. Its sentence was "a reading nobody asked is not a
            // reading", and that argument is about when the row exists rather
            // than about the text: the row is still created only by somebody
            // asking. `StoreOrderRequest` has always accepted a reading line
            // with no question, `Rules` only refuses a question on a product
            // that does not allow one, and `mail.reading-to-write` has always
            // had a branch reading "No question was asked." So a question-less
            // reading is a reachable purchase today, and NOT NULL here would
            // have thrown at settlement, after the money was taken, on an order
            // the API accepted.
            //
            // **What is not decided here is whether that purchase should be
            // possible.** Requiring a question at placement is a change to the
            // contract and to the website, and it belongs to whoever owns that
            // question, not to the unit that lands the table. Recorded on #69.
            $table->text('question')->nullable();

            // Where it goes. Captured, not joined, because a gift's querent may
            // have no customers row at all and deliberately never gets one: an
            // account exists for people who transact, and somebody who was
            // given a present has bought nothing.
            //
            // The address is the half that cannot go missing, so it is the half
            // that is NOT NULL. The name is nullable the way a customer's has
            // been since #52.
            $table->string('querent_email');
            $table->string('querent_name')->nullable();

            // The clock the delivery window runs from. **Due is computed from
            // this at read time and never stored**, since a stored due date is a
            // second writer for a value that already has one and goes stale the
            // day a window changes.
            $table->timestamp('asked_at');

            // Stamped one reading at a time, because she delivers a reading and
            // not an order. An order becoming fulfilled follows from it.
            $table->timestamp('delivered_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('readings');
    }
};
