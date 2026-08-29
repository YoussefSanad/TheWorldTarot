<?php

namespace Tests\Fixtures\Claims\Unresolved;

/**
 * A comment citing a section and a rule that CLAUDE.md does not have.
 *
 * CLAUDE.md section 42 does not exist, and neither does section 0.9 of
 * CLAUDE.md. Hard rule 99 does not exist either. This is what renumbering
 * leaves behind, which is the thing CLAUDE.md opens by warning about and had
 * nothing but memory holding it up.
 */
class RenumberedCitation
{
    public const Cited = 42;
}
