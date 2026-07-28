<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove translated slugs and their history.
     *
     * A product is now addressed by its key, a fixed enum value that is the
     * same in every language and cannot be edited. Nothing can change a link,
     * so nothing needs to record what a link used to be.
     *
     * Dropping the column takes its per-locale unique expression indexes with
     * it, so they need no separate statement.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('slug');
        });

        Schema::dropIfExists('slug_history');
    }

    /**
     * Reverse the migrations.
     *
     * Restores the column so the schema can go backwards, but not the data or
     * the history table. Both belong to a design this one replaced.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->jsonb('slug')->nullable();
        });
    }
};
