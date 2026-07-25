<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Creates the per-locale unique index over a translatable slug column.
 *
 * Lives here rather than inline in a migration because enabling a locale later
 * needs the same statement, and M3 needs it again for cards. One definition
 * means the index built for a second language is identical to the first.
 *
 * See .claude/translation.md section 9.
 */
final class SlugIndex
{
    public static function createFor(string $table, string $locale): void
    {
        self::guard($table, $locale);

        // Partial on deleted_at so a soft deleted record does not hold its slug
        // hostage against a replacement.
        DB::statement(sprintf(
            "create unique index if not exists %s on %s ((slug->>'%s')) where deleted_at is null",
            self::nameFor($table, $locale),
            $table,
            $locale,
        ));
    }

    public static function dropFor(string $table, string $locale): void
    {
        self::guard($table, $locale);

        DB::statement('drop index if exists '.self::nameFor($table, $locale));
    }

    public static function nameFor(string $table, string $locale): string
    {
        return "{$table}_slug_".strtolower($locale).'_unique';
    }

    /**
     * These values are interpolated rather than bound, because Postgres will
     * not take a parameter in a DDL statement. They come from config and from
     * our own migrations, never from a request, and are checked anyway.
     */
    private static function guard(string $table, string $locale): void
    {
        if (! preg_match('/^[a-z_]+$/', $table)) {
            throw new InvalidArgumentException("Refusing to index unexpected table [{$table}].");
        }

        if (! preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $locale)) {
            throw new InvalidArgumentException("Refusing to index unexpected locale code [{$locale}].");
        }
    }
}
