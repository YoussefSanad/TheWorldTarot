<?php

use App\Support\SlugIndex;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * A unique index per locale over the slug jsonb key.
     *
     * This is the concrete reason translatable columns are jsonb rather than
     * json: json cannot be indexed usefully on Postgres. See
     * .claude/translation.md section 9.
     *
     * Uniqueness is per locale, not global. An English slug and the same string
     * as an Italian slug on another product are different URLs, and both must
     * be allowed.
     *
     * This covers the locales configured when it runs. Enabling a locale later
     * adds its index in that deploy. The statements are IF NOT EXISTS so a
     * fresh database built from a config that already includes the locale
     * creates it here, and the later migration is a no-op rather than a
     * failure.
     */
    public function up(): void
    {
        foreach (array_keys(config('localization.available')) as $locale) {
            SlugIndex::createFor('products', $locale);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (array_keys(config('localization.available')) as $locale) {
            SlugIndex::dropFor('products', $locale);
        }
    }
};
