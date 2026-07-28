<?php

namespace App\Support;

/**
 * Resolves the locale for a request from an explicit value.
 *
 * The backend never guesses. It takes what the request states, validates it
 * against the registry, and falls back to the default. Negotiation from browser
 * headers is the frontend's job: it routes on locale in the path for SEO, so it
 * already knows the answer, and re-deriving it here lets the two disagree.
 *
 * See .claude/translation.md section 7.
 */
final class LocaleResolver
{
    public static function default(): string
    {
        return Locales::default();
    }

    /**
     * Resolution rules, in order:
     *
     * 1. A live language is served to anyone
     * 2. A language in preview is served only to a signed-in admin
     * 3. Anything else, including absent, unknown, or still being translated,
     *    falls back to the default
     */
    public static function resolve(?string $requested, bool $isAdmin = false): string
    {
        if ($requested === null || $requested === '') {
            return self::default();
        }

        $locale = Locales::find(strtolower($requested));

        if ($locale === null || ! $locale->isServableTo($isAdmin)) {
            return self::default();
        }

        return $locale->code;
    }
}
