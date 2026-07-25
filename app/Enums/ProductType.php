<?php

namespace App\Enums;

/**
 * What kind of sellable thing a product row is.
 *
 * Everything sellable lives in one table, distinguished by this. See
 * .claude/database.md.
 *
 * This enum is part of the frontend contract: the site maps presentation to it,
 * so values are stable and renaming one is a coordinated change.
 */
enum ProductType: string
{
    // A tarot reading. Has a public page and a price.
    case Reading = 'reading';

    // The viewing room pass. Bought once, grants access rather than delivering
    // something.
    case Pass = 'pass';

    // Bought alongside something else and has no page of its own, such as the
    // 24 hour rush.
    case Addon = 'addon';
}
