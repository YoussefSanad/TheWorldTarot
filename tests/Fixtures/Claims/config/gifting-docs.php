<?php

/*
 * A config file shaped like `config/scramble.php`: a value that is prose, read
 * by somebody who is not a developer here.
 *
 * The description below is served rather than compiled, so a dead path in it is
 * a dead end for the reader and nothing in the code ever touches it. That is
 * why a `config/` value is read for documents, and why nowhere else is.
 */
return [
    'description' => 'The written contract lives in docs/api/gift-contract.md. Read both.',

    // Data, not prose. A key that happens to look like a path is not a claim,
    // and the check is not confused by one.
    'template' => 'emails.gifting.md',
];
