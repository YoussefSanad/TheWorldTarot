<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Somebody bought a reading for somebody else, and nobody has asked for it
     * yet.
     *
     * `docs/adr/0004-a-reading-is-a-row-of-its-own.md` for why this is a line
     * bought for another person rather than a second order, and
     * `docs/adr/0005-gift-codes-do-not-expire.md` for what a row with a null
     * `redeemed_at` is: an outstanding obligation that never lapses, which is
     * the whole of the query that finds them.
     *
     * **A gift row exists from placement and its code does not, and that split
     * is decided here.** Neither ADR settles when the row comes into existence,
     * so the reasoning is written down where the columns are:
     *
     * - The buyer's four fields are typed at checkout, before the money
     *   arrives, and have to survive until it does. `order_lines.question` is
     *   the existing precedent and gives the reason: a reading may not exist
     *   before the payment does, so what the buyer typed lives on the row that
     *   was written when they typed it
     * - The code cannot travel with them. A code minted at placement is a live
     *   bearer credential backed by a payment that may never arrive, one per
     *   abandoned checkout. It is minted at settlement instead, in B6, and
     *   `code` is nullable for exactly the window in between
     * - The alternative was four more columns on `order_lines` and a whole row
     *   written at settlement, which is the same four values in two places for
     *   the life of the schema
     *
     * A codeless row is inert: nothing can be looked up with it and nothing can
     * be redeemed. An abandoned checkout therefore leaves a record of what was
     * asked for and no credential at all.
     */
    public function up(): void
    {
        Schema::create('gifts', function (Blueprint $table) {
            $table->id();

            // The line that was gifted. **Unique**, because one line is one
            // thing bought once, and a second gift hanging off it is one
            // payment owing two presents. Restricted, the same as
            // `readings.order_line_id` and `order_lines.product_id`: deleting
            // the record of a purchase must fail loudly rather than quietly
            // orphan what was bought.
            $table->foreignId('order_line_id')->unique()->constrained()->restrictOnDelete();

            // What the recipient types in to claim it.
            //
            // **Nullable only until settlement**, per the docblock above. A
            // unique index does not treat nulls as equal, so "not minted yet"
            // is not a value two abandoned checkouts can collide on. An empty
            // string would have been, which is why this is null and not that.
            //
            // **Not derived from anything about the gift.** The client's own
            // `3CARD10021` names its value and can be counted to;
            // `Order::mintPayToken` already argues that point for the token
            // beside it. B6 owns the alphabet and the entropy.
            $table->string('code')->nullable()->unique();

            // Where the code is sent, and the only address in this transaction
            // that nothing can check. The buyer never receives the code, so a
            // typo here is unrecoverable and nothing on this side can tell that
            // the mail arrived nowhere. The contract asks the frontend to
            // collect it twice for that reason.
            $table->string('recipient_email');

            // Who it is from, and **not a name**. It is what the recipient's
            // mail is signed and subject lined with, so "Mum" is a truer answer
            // than what is on the card, and an order may carry no name at all.
            // An unsolicited mail carrying a code and a link from a brand
            // somebody has never bought from is shaped exactly like phishing,
            // and a named sender is the strongest signal it is real.
            $table->string('signature');

            // The buyer's own words to the recipient. Optional, and the only
            // one of the four that is.
            $table->text('message')->nullable();

            // Which language the recipient's mail is written in. **A property
            // of the gift rather than of the buyer**, because the person it is
            // sent to never chose it and may not read the language the order
            // was placed in. Filled from the order's language when the buyer
            // does not say.
            //
            // Not in ADR 0004's sketch of this table. Added here because the
            // mail is rendered from it and there is nothing else to render it
            // from: an order carries no language of its own.
            $table->string('locale');

            // **Null is an outstanding obligation, not "unknown".** ADR 0005
            // accepts that a gift never expires, so this column is the whole of
            // the liability, and the panel screen B9 builds is the only place
            // anybody can see it.
            //
            // **There is deliberately no `expires_at` beside it.** ADR 0005
            // says a column nothing writes and nothing reads is a column the
            // next person assumes is enforced somewhere.
            $table->timestamp('redeemed_at')->nullable();

            $table->timestamps();
        });

        Schema::table('readings', function (Blueprint $table) {
            // B3 landed this column nullable and unconstrained, because there
            // was no table to point it at and the shape being proved was worth
            // landing whole. This is the unit that gives it one.
            //
            // Restricted rather than nulled on delete. A reading that forgot it
            // was a present is a reading nobody can explain the existence of,
            // and the querent it was delivered to is then unaccounted for.
            $table->foreign('gift_id')->references('id')->on('gifts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('readings', function (Blueprint $table) {
            $table->dropForeign(['gift_id']);
        });

        Schema::dropIfExists('gifts');
    }
};
