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
     * The languages this site can be read in.
     *
     * Written when a config file also listed the locales and won over this
     * table. That file is gone and this table is now the whole registry. A
     * later migration renames `state` to `status` and adds the names. See
     * .claude/translation.md section 3.
     *
     * Values are literals rather than config lookups or enum cases, because a
     * migration that has already run must not break when either changes.
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
            'code' => 'en',
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
