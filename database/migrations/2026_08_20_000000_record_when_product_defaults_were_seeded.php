<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When the seeder last filled a product's blanks, so it only ever does it
     * once.
     *
     * `ProductSeeder` fills empty copy and zero prices, which is what stops a
     * fresh database having an empty shop. Run on every deploy, that rule has a
     * nasty second meaning: **it also undoes emptying something.** A price the
     * client sets back to 0, or a description they clear, is refilled with a
     * developer's default on the next deploy, and the panel offers no other way
     * to take a product down — `is_published` is not on the form.
     *
     * So the intent is "apply defaults to a product that has never had them",
     * not "keep these fields non-empty forever", and that is a fact about the
     * past which has to be recorded rather than inferred. Inferring it from the
     * data is what the old `! $product->exists` check did, and it could not tell
     * a row seeded blank in July from one edited to blank yesterday.
     *
     * Null means never seeded, which is correct for every existing row: they
     * were created by the version that seeded nothing, so they are exactly the
     * rows that still need filling.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->timestamp('defaults_seeded_at')->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('defaults_seeded_at');
        });
    }
};
