<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where the website lives
    |--------------------------------------------------------------------------
    |
    | This API renders nothing a customer ever sees, per hard rule 1, so any
    | link we put in an email has to point at the Next.js app rather than here.
    |
    | Per environment: staging mail must never send somebody to production.
    |
    */

    'url' => env('FRONTEND_URL', 'http://localhost:3000'),

    /*
    |--------------------------------------------------------------------------
    | Pages we link people to
    |--------------------------------------------------------------------------
    |
    | Owned by the frontend partner, which is why they are configuration rather
    | than strings in a mailable. When they tell us what they built, this is one
    | line and a deploy instead of a code change.
    |
    | Each receives `token` and `email` as query parameters, and both have to be
    | sent back to this API unchanged for the reset to work.
    |
    */

    'paths' => [
        'reset_password' => env('FRONTEND_RESET_PASSWORD_PATH', '/reset-password'),

        // Where somebody who bought before they had an account chooses their
        // first password. A separate page from the reset above, because the
        // words on it are different: nothing is being reset.
        'set_password' => env('FRONTEND_SET_PASSWORD_PATH', '/set-password'),
    ],

];
