<?php

namespace Tests\Fixtures\Claims\Unresolved;

/**
 * A comment naming a config file that does not exist.
 *
 * The gifting milestone has not shipped, so config/gifting.php is prose
 * pointing at nothing, and config('gifting.codes.length') reads a file that is
 * not there either. Both are the rename shape: the file moves, the comment
 * describing it does not.
 */
class MissingConfig
{
    public const Length = 12;
}
