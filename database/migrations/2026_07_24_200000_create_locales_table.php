<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Config (config/localization.php) declares which locales CAN be served.
     * This table holds the runtime state of each: available, preview, or live.
     * A row whose code is absent from config.available is treated as nonexistent.
     * See docs/plans/milestone-1-plan.md and .claude/translation.md.
     */
    public function up(): void
    {
        Schema::create('locales', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('state')->default('available');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // The default locale is always live. Launch is English only.
        DB::table('locales')->insert([
            'code' => config('localization.default', 'en'),
            'state' => 'live',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('locales');
    }
};
