<?php

namespace App\Support;

use App\Models\Locale;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/**
 * Reads the locale registry, once per request.
 *
 * Everything that needs to know which languages exist goes through here: the
 * resolver, the admin panel's language tabs, the public language list. One
 * cached read rather than a query per caller.
 *
 * Guarded against the table not existing, because the admin panel is built
 * while the framework boots, including during the migration that creates it.
 */
final class Locales
{
    private static ?Collection $cache = null;

    /**
     * The language everything falls back to. Laravel's own setting, so there is
     * no bespoke config file and no special row in the table.
     *
     * **`app.fallback_locale`, not `app.locale`.** `app()->setLocale()` writes
     * `app.locale`, so reading that would mean the default silently became
     * whatever language the current request asked for. Every completeness check
     * exempts the default language, so that turned the entire gate off for any
     * request in another language.
     */
    public static function default(): string
    {
        return config('app.fallback_locale');
    }

    /**
     * @return Collection<int, Locale>
     */
    public static function all(): Collection
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        if (! Schema::hasTable('locales')) {
            return self::$cache = collect();
        }

        return self::$cache = Locale::ordered()->get();
    }

    /**
     * Every language code the panel can edit, including drafts. Falls back to
     * the default alone when the table is empty or missing, so the panel is
     * never left with no language at all.
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        $codes = self::all()->pluck('code')->all();

        return $codes === [] ? [self::default()] : $codes;
    }

    /**
     * The default language's name, for telling the client which tab to switch
     * to. Falls back to the code if the registry has no row for it.
     */
    public static function defaultName(): string
    {
        return self::find(self::default())?->english_name ?? self::default();
    }

    public static function find(string $code): ?Locale
    {
        return self::all()->firstWhere('code', $code);
    }

    /**
     * @return Collection<int, Locale>
     */
    public static function live(): Collection
    {
        return self::all()->filter->isLive()->values();
    }

    /**
     * Tests change the registry mid-run, and the panel reads it during boot.
     */
    public static function forget(): void
    {
        self::$cache = null;
    }
}
