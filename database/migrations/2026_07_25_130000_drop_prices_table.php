<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove the polymorphic prices table built in M1.
     *
     * Superseded by the single `products` table, whose prices are three NOT NULL
     * integer columns. The reasoning is in .claude/database.md. The table was
     * never attached to a real model.
     *
     * The original create migration is deleted rather than kept, so a fresh
     * database never creates this table at all. This drop exists for any
     * environment that already ran that migration, which is why it is guarded.
     */
    public function up(): void
    {
        Schema::dropIfExists('prices');
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
