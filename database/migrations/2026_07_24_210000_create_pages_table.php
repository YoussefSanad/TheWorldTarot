<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Pages are the M1 vehicle for proving the translation pattern (see
     * docs/plans/milestone-1-plan.md). Translatable fields are jsonb, never json,
     * so per-locale keys can be indexed by the slug expression indexes added in
     * later milestones. See .claude/translation.md section 3.
     */
    public function up(): void
    {
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->jsonb('title');
            $table->jsonb('body');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pages');
    }
};
