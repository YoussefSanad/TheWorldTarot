<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One row per priceable thing per currency. Prices are fixed per currency
     * and set by hand, never converted from a base price, so there is no rate
     * column and no base currency here by design. See .claude/database.md.
     *
     * Polymorphic from the start: reading products (M2) and the viewing room
     * pass (M4) attach to this table without a schema change. No priceable
     * model exists yet in M1, so the behaviour is proven against a fixture.
     */
    public function up(): void
    {
        Schema::create('prices', function (Blueprint $table) {
            $table->id();

            // Written out rather than morphs() because the unique index below
            // already indexes (type, id) as its leading columns. morphs() would
            // add a second index covering the same prefix.
            $table->string('priceable_type');
            $table->unsignedBigInteger('priceable_id');

            // ISO 4217, always three characters and always uppercase.
            $table->string('currency_code', 3);

            // Integer minor units, hard rule 5. Unsigned because a price is
            // never negative; refunds and adjustments are not prices.
            $table->unsignedInteger('amount');

            $table->timestamps();

            // One price per thing per currency. The constraint is what stops a
            // product quietly holding two conflicting GBP prices.
            $table->unique(['priceable_type', 'priceable_id', 'currency_code']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('prices');
    }
};
