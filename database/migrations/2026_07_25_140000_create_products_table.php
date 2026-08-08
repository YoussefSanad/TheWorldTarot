<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One table for everything sellable: readings, the viewing room pass, and
     * add-ons. Roughly seven rows for the life of the project, sharing almost
     * every column, differing in behaviour rather than structure. Reasoning in
     * .claude/database.md.
     *
     * Rows are seeded, never created through the admin panel.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();

            // Stable identifier for seeds and for code that needs a specific
            // product. Never translated, never edited, never shown to a
            // customer.
            $table->string('key')->unique();

            // String columns with PHP enum casts rather than Postgres enum
            // types, which are painful to alter later. See .claude/database.md.
            $table->string('type');
            $table->string('fulfillment');

            // A question is optional when buying a reading, never required.
            $table->boolean('allows_question')->default(false);

            // Translatable, jsonb so per-locale keys can be indexed. Slug is
            // nullable because add-ons have no page of their own.
            $table->jsonb('slug')->nullable();
            $table->jsonb('name');
            $table->jsonb('short_description');
            $table->jsonb('long_description');

            // Integer minor units, hard rule 5. NOT NULL is the whole point:
            // nothing may exist without a price in every currency, enforced
            // here rather than by application code that can be bypassed.
            // A fourth currency is a migration, accepted knowingly.
            $table->unsignedInteger('price_usd');
            $table->unsignedInteger('price_eur');
            $table->unsignedInteger('price_gbp');

            $table->boolean('is_published')->default(false);
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            // Soft deletes because an order will reference a product from M5.
            // Deleting one must never orphan a purchase.
            $table->softDeletes();

            // The public list is published products of a type, in order.
            $table->index(['type', 'is_published', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
