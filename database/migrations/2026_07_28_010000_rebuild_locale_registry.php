<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Make the locales table the whole registry.
     *
     * There was a config file listing which locales could exist, and a rule
     * that config won over the database. Both are gone. One table now answers
     * every question about a language, which is also what makes pulling one
     * a status change rather than a deploy. See .claude/translation.md
     * section 3.
     *
     * `state` becomes `status` and `available` becomes `draft`, because a
     * language nobody is served is a draft, and "available" said the opposite
     * of what it meant.
     */
    public function up(): void
    {
        Schema::table('locales', function (Blueprint $table) {
            $table->renameColumn('state', 'status');
        });

        Schema::table('locales', function (Blueprint $table) {
            // Nullable first, because existing rows have no names yet.
            $table->string('english_name')->nullable();
            $table->string('native_name')->nullable();
        });

        DB::table('locales')->where('status', 'available')->update(['status' => 'draft']);

        // The names the config file used to carry.
        $names = [
            'en' => ['english_name' => 'English', 'native_name' => 'English'],
            'es' => ['english_name' => 'Spanish', 'native_name' => 'Español'],
        ];

        foreach ($names as $code => $values) {
            DB::table('locales')->where('code', $code)->update($values);
        }

        // Anything else keeps its code as a placeholder rather than staying
        // null, so the column can be required from here on.
        DB::table('locales')->whereNull('english_name')->update([
            'english_name' => DB::raw('code'),
            'native_name' => DB::raw('code'),
        ]);

        Schema::table('locales', function (Blueprint $table) {
            $table->string('english_name')->nullable(false)->change();
            $table->string('native_name')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('locales')->where('status', 'draft')->update(['status' => 'available']);

        Schema::table('locales', function (Blueprint $table) {
            $table->dropColumn(['english_name', 'native_name']);
            $table->renameColumn('status', 'state');
        });
    }
};
