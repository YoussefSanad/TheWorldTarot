<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two videos, briefly: the one customers are watching and the one being
     * prepared to replace it.
     *
     * Replacing a video must never leave a gap in the Viewing Room. With one
     * column, uploading a replacement took the card off the site for the few
     * minutes Bunny took to transcode, because being watchable depended on the
     * status of the only video there was.
     *
     * So `bunny_video_id` now holds only a video that is ready to watch, and
     * anything in flight sits in `bunny_incoming_video_id` until Bunny says it
     * is finished. Being watchable stops depending on the status at all, and a
     * failed replacement leaves the old video playing.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->string('bunny_incoming_video_id')->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropColumn('bunny_incoming_video_id');
        });
    }
};
