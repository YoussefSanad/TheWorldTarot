<?php

namespace App\Enums;

/**
 * The lifecycle state of a locale, per translation.md section 4.
 *
 * Gating happens at the locale level, not per record or per field. A locale is
 * public only when it is Live.
 */
enum LocaleState: string
{
    // Translation in progress. Visible only as a tab in Filament. Never served.
    case Available = 'available';

    // Reviewable by authenticated admins via an explicit ?locale=, nobody else.
    case Preview = 'preview';

    // Public. Appears in the public locale list and is served to everyone.
    case Live = 'live';
}
