<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The poster frame leaves the system.
     *
     * It was a second per-card upload that gated both the Viewing Room and the
     * homepage draw, and on 7 August 2026 it was the reason the draw returned a
     * 404 on staging: every card there had a poster of null, so the pool the
     * draw narrows to was empty whatever the films were doing.
     *
     * What replaces it is the card's own picture, which every card on the
     * website has by definition. The frontend had already stopped reading the
     * field before this ran.
     *
     * Any poster objects still in the media bucket are left where they are. A
     * migration that reaches into a bucket is worse than the handful of files
     * it would tidy, and none were ever uploaded.
     *
     * Literals only, no enums or support classes, per the trap list in
     * docs/CHECKPOINT.md.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn('poster_path');
        });
    }

    /**
     * Brings the column back empty. The paths it used to hold are gone, which is
     * the point: nothing reads them and nothing is coming back to.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('poster_path')->nullable();
        });
    }
};
