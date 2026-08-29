<?php

namespace Tests\Fixtures\Claims\Unresolved;

/**
 * A comment pointing at a document that is not there.
 *
 * This is the `config/scramble.php` shape: the document was written, the
 * comment was written beside it, and the document was deleted later without
 * anything asking what still pointed at it. See
 * docs/api/never-written-contract.md.
 */
class DeletedDocument
{
    // A second dead path, on its own line, so the report has to name both
    // rather than the first. See docs/plans/milestone-99-plan.md.
    public const Reason = 'deleted';
}
