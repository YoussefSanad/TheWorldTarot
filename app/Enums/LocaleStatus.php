<?php

namespace App\Enums;

/**
 * How far along a language is.
 *
 * Gating happens at the language level for launching one, and per record for
 * content added afterwards. See .claude/translation.md sections 1 and 4.
 */
enum LocaleStatus: string
{
    // Translations are being entered. A tab in the panel and nothing more.
    case Draft = 'draft';

    // Served to a signed-in admin through the locale in the URL, so the whole
    // site can be signed off before customers can reach it.
    case Preview = 'preview';

    // Public. Appears in the language list and is served to everyone.
    case Live = 'live';
}
