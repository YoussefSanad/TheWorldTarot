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
    | **Both carry a trailing slash, and it is not decoration.** The partner
    | confirmed on 28 August 2026 that the website is a static export built with
    | `trailingSlash`, so `/set-password` is a redirect to `/set-password/`.
    | Without the slash every receipt and every reset mail costs the person
    | reading it a hop, which is the kind of thing a mail client strips or a
    | scanner follows and burns the single-use token on.
    |
    */

    'paths' => [
        'reset_password' => env('FRONTEND_RESET_PASSWORD_PATH', '/reset-password/'),

        // Where somebody who bought before they had an account chooses their
        // first password. A separate page from the reset above, because the
        // words on it are different: nothing is being reset.
        'set_password' => env('FRONTEND_SET_PASSWORD_PATH', '/set-password/'),

        // Where Stripe returns somebody who paid. It arrives holding a Checkout
        // Session id and asks this API what the payment did, because the id is
        // opaque and a publishable key cannot resolve it.
        'checkout_complete' => env('FRONTEND_CHECKOUT_COMPLETE_PATH', '/checkout/complete/'),

        // The reading index, and the root of every reading page. Where a
        // cancelled checkout lands, on the page it started from when the caller
        // said which one that was.
        'readings' => env('FRONTEND_READINGS_PATH', '/readings/'),
    ],

];
