<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add Spanish, not yet public.
     *
     * The row is what makes the Spanish tab appear in the panel, with no
     * migration per language. Its status keeps it out of the public site until
     * every record is flagged finished in it.
     *
     * This originally also created a per-locale unique slug index. Translated
     * slugs were removed on 27 July 2026 in favour of stable keys, so that part
     * is gone. See .claude/translation.md section 9.
     */
    public function up(): void
    {
        DB::table('locales')->updateOrInsert(
            ['code' => 'es'],
            [
                // Literal rather than an enum case: a migration that has already run must
                // not break when the enum is renamed.
                'state' => 'available',
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('locales')->where('code', 'es')->delete();
    }
};
