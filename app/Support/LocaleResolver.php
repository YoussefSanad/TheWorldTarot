<?php

namespace App\Support;

use App\Enums\LocaleState;
use App\Models\Locale;

/**
 * Resolves the locale for a request from an explicit value, per
 * .claude/translation.md section 6.
 *
 * The backend never guesses. It takes what the request states, validates it,
 * and falls back to the default. Negotiation from browser headers is the
 * frontend's job: it routes on locale in the path for SEO, so it already knows
 * the answer, and re-deriving it here lets the two disagree.
 */
final class LocaleResolver
{
    public static function default(): string
    {
        return config('localization.default');
    }

    /**
     * Resolution rules, in order:
     *
     * 1. A live locale is used
     * 2. A preview locale is used only for an authenticated admin
     * 3. Anything else, including absent, unknown, or still being translated,
     *    falls back to the default
     */
    public static function resolve(?string $requested, bool $isAdmin = false): string
    {
        if ($requested === null || $requested === '') {
            return self::default();
        }

        $locale = Locale::configured()
            ->where('code', strtolower($requested))
            ->first();

        if ($locale === null) {
            return self::default();
        }

        return match ($locale->state) {
            LocaleState::Live => $locale->code,
            LocaleState::Preview => $isAdmin ? $locale->code : self::default(),
            LocaleState::Available => self::default(),
        };
    }
}
