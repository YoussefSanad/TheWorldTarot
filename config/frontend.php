<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Where the website lives
    |--------------------------------------------------------------------------
    |
    | Every page a customer ever sees is the Next.js app's, per hard rule 1, so
    | any link we put in an email has to point there rather than here.
    |
    | Per environment: staging mail must never send somebody to production.
    |
    | **This said "This API renders nothing a customer ever sees" until
    | 1 September 2026**, and issue #53 made the sentence untrue twice over. The
    | purchase confirmation is a designed HTML document rendered by this
    | application, and its gold frame is eighteen images served to a customer's
    | mail client from this host out of `public/email/`. Neither is a page and
    | neither is UI, so hard rule 1 is intact as written, but the flat claim was
    | not, and a reader checking whether something belongs here deserves the
    | narrower version.
    |
    | **Where those images live is a `mail.assets.url` away from changing**, and
    | moving them to a CDN or to the frontend's own `public/` is the change to
    | make if serving them from the API is judged the wrong side of that line.
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

        // Where the viewing room lives: where a cancelled pass checkout lands
        // and where the pass receipt sends its holder. The pass is not a
        // reading, so it has no page under the reading index.
        'viewing_room' => env('FRONTEND_VIEWING_ROOM_PATH', 'viewing-room'),

        // Where somebody spends a gift code. One page for every reading rather
        // than one per reading, and **the code arrives as a query parameter and
        // never as a path segment**: the website is a static export, so a path
        // per code would need every code pre-rendered at build time, which is
        // not a thing codes can be. The frontend's third ADR, "redemption is a
        // page of its own", decides it, and API_CONTRACT.md records it under
        // Gifting.
        //
        // **The query string is in browser history and can leave in a
        // `Referer`, and that is accepted for this credential and not for the
        // other one.** A pay token may never reach an address bar. A gift code
        // is built to, because the recipient has to be able to click a link and
        // to type the same characters off the mail when the link fails them.
        'redeem' => env('FRONTEND_REDEEM_PATH', '/redeem/'),
    ],

];
