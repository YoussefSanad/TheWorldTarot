<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The 22 Major Arcana, and any card Jennifer adds later.
     *
     * Cards are the one thing she creates rather than edits, because she has a
     * binding commitment to existing pass holders that she can add future cards
     * without a developer.
     *
     * Literals only in here, no enums, config keys or support classes. Three
     * migrations had to be rewritten when the things they referenced were
     * renamed, and a migration has to keep running long after that.
     */
    public function up(): void
    {
        Schema::create('cards', function (Blueprint $table) {
            $table->id();

            // The Major Arcana position, 0 to 21 today, and also the order they
            // appear in. Not range checked: a twenty third card has to be a row
            // she can add, or the commitment above is broken by a constraint.
            //
            // There is no slug. A card is a modal opened from the Library, so
            // its identifier never reaches an address bar and a frozen,
            // generated, human readable one would be machinery paying for
            // nothing. Addressed by id instead.
            $table->unsignedInteger('number');

            // Translatable, jsonb rather than json so per-locale keys can be
            // indexed. Never json. See .claude/translation.md section 6.
            $table->jsonb('name');
            $table->jsonb('short_description');
            $table->jsonb('long_description');

            // A developer-level switch, defaulting on, matching products.
            // Nothing here is ever retired, and what decides whether a customer
            // sees a card is the card itself: its copy, in their language.
            // Not editable in the panel.
            $table->boolean('is_published')->default(true);

            $table->timestamps();

            // Soft deletes because M4 attaches a video to a card, and a deleted
            // row would orphan it.
            $table->softDeletes();

            // The public list is published cards in order.
            $table->index(['is_published', 'number']);
        });

        // Unique on number, but only among cards that are not deleted.
        //
        // A plain unique index would mean deleting a card and adding it back
        // fails on a constraint, because the soft deleted row still holds the
        // number. That is a dead end for the one person who cannot get out of
        // it, in the one resource she creates rows in.
        //
        // Postgres only. Written as a statement because the schema builder has
        // no partial unique index.
        DB::statement('create unique index cards_number_unique on cards (number) where deleted_at is null');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cards');
    }
};
