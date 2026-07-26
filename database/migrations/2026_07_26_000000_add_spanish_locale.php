<?php

use App\Enums\LocaleState;
use App\Support\SlugIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Enable Spanish for the admin panel only.
     *
     * The config key in config/localization.php is what makes the Spanish tab
     * appear on every translatable resource, with no migration and no code
     * change. This migration does the two things config cannot:
     *
     * 1. Creates the locales row at `available`, which means the tab exists and
     *    nobody outside the panel is ever served Spanish. Not the public list,
     *    not ?locale=es, not even for an admin
     * 2. Adds the per-locale unique slug index, so two products cannot share a
     *    Spanish web address. Every locale needs its own, since uniqueness is
     *    per locale rather than global
     *
     * To review the real site in Spanish before customers can, move the row to
     * `preview`, which serves it through an explicit ?locale=es to a signed-in
     * admin and to nobody else. Going `live` additionally needs lang/es/ in
     * this repo and Spanish messages in the frontend repo, or the content is
     * translated while every button is still English.
     */
    public function up(): void
    {
        DB::table('locales')->updateOrInsert(
            ['code' => 'es'],
            [
                'state' => LocaleState::Available->value,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        SlugIndex::createFor('products', 'es');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        SlugIndex::dropFor('products', 'es');

        DB::table('locales')->where('code', 'es')->delete();
    }
};
