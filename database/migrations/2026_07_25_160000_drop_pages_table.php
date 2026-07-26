<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the `pages` table built in M1.
     *
     * It existed to prove the translation pattern before any real content model
     * existed. `products` now does that with real content, so keeping it would
     * leave Jennifer looking at a Pages section in the admin panel that does
     * nothing.
     *
     * Marketing copy is config-declared blocks rather than database pages, so
     * nothing replaces this table. See CLAUDE.md section 4. FAQ and legal
     * documents get their own models in M6.
     *
     * The original create migration is deleted, so a fresh database never makes
     * this table. This drop exists for any environment that already ran it.
     */
    public function up(): void
    {
        Schema::dropIfExists('pages');
    }

    /**
     * Reverse the migrations.
     *
     * Deliberately empty. Recreating a table this design no longer has would
     * put back something nothing reads.
     */
    public function down(): void
    {
        //
    }
};
