<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A card must have a picture before it is on the website.
     *
     * The Library shows the card, so a card with no picture is not a card
     * anyone can be shown. It sits alongside the copy in the same calculation:
     * nothing is switched on, a card simply becomes visible once it is finished.
     *
     * Nullable, because a card is created before its picture is uploaded, the
     * same way it is created before its copy is written.
     *
     * The path only. Which disk it lives on is configuration, so moving the
     * bucket never touches a row.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('image_path')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
