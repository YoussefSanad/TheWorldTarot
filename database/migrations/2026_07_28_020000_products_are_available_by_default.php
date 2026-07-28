<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Readings are on the website by default and are never taken off.
     *
     * The panel used to carry a "Show on the website" switch. It was
     * misleading: nothing here is ever retired, so the switch only ever had one
     * useful position and offered a way to take the shop down by accident.
     *
     * What decides whether customers see a reading is now the reading itself:
     * it needs its copy and a real price in every currency. That is calculated,
     * not stored, for the same reason translation completeness is: a stored
     * flag is stale the moment the thing it described changes.
     *
     * The column stays as a developer-level switch for the day something does
     * need pulling. It is not editable in the panel.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_published')->default(true)->change();
        });

        DB::table('products')->update(['is_published' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_published')->default(false)->change();
        });
    }
};
