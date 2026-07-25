<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every slug a record has ever had, per locale.
     *
     * Captured from day one deliberately, per .claude/translation.md section 9.
     * **No redirect handling is built on top of it yet.** The point is to have
     * the data when redirects arrive, rather than discovering later that the
     * history needed to build them was never recorded. A changed slug without
     * this table is a permanently dead URL and a lost search ranking.
     *
     * Polymorphic because cards get translated slugs in M3 and need the same
     * history. This is a genuine many-model concern, unlike prices.
     */
    public function up(): void
    {
        Schema::create('slug_history', function (Blueprint $table) {
            $table->id();

            $table->morphs('sluggable');

            $table->string('locale');
            $table->string('slug');

            // The moment the record stopped using this slug.
            $table->timestamp('created_at')->nullable();

            // How a redirect will be looked up when it is built: an incoming
            // old URL in a given locale.
            $table->index(['locale', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('slug_history');
    }
};
