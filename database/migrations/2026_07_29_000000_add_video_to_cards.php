<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Living Tarot video for a card, and the Viewing Room it sits behind.
     *
     * Four things have to be true before a card is watchable, and they arrive at
     * different times: Jennifer uploads a master, a job hands it to Bunny, Bunny
     * says it is ready, and she uploads a poster frame. Every one of them is
     * nullable because a card exists long before any of it.
     *
     * Literals only, no enums or support classes, per the trap list in
     * docs/CHECKPOINT.md.
     */
    public function up(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            // Bunny's identifier for the video. Written before the bytes are
            // uploaded, so a retried job reuses the same video instead of
            // leaving a second copy behind. Unique, since two cards sharing one
            // video would mean an upload went to the wrong card.
            $table->string('bunny_video_id')->nullable()->unique();

            // pending, processing, ready, failed. A string column with a PHP
            // enum cast, per .claude/database.md. Null means no video at all,
            // which is every card until she uploads one.
            $table->string('video_status')->nullable();

            // The master, on the private disk. Kept after Bunny has it, because
            // it is the only copy that is not on her laptop or inside a vendor.
            $table->string('video_master_path')->nullable();

            // The frame the player rests on. On the public disk, since it is a
            // still rather than the thing the paywall protects.
            $table->string('poster_path')->nullable();

            $table->timestamp('video_uploaded_at')->nullable();

            // The Viewing Room is published cards with a ready video.
            $table->index(['is_published', 'video_status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cards', function (Blueprint $table) {
            $table->dropIndex(['is_published', 'video_status']);

            $table->dropColumn([
                'bunny_video_id',
                'video_status',
                'video_master_path',
                'poster_path',
                'video_uploaded_at',
            ]);
        });
    }
};
