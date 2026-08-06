<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bunny Stream
    |--------------------------------------------------------------------------
    |
    | The Living Tarot videos. Per environment, because a Stream library carries
    | one webhook URL, so staging and production have separate libraries and
    | sharing one would mean staging transcodes calling production.
    |
    | `key` writes and reads through the API. `token_key` signs playback URLs
    | and is a different value found in the library's settings. Mixing the two
    | up produces a 403 that reads like an expiry problem.
    |
    */

    'bunny' => [
        'key' => env('BUNNY_API_KEY'),
        'library_id' => env('BUNNY_LIBRARY_ID'),
        'token_key' => env('BUNNY_TOKEN_KEY'),
        'cdn_hostname' => env('BUNNY_CDN_HOSTNAME'),
        'api_url' => env('BUNNY_API_URL', 'https://video.bunnycdn.com'),

        // A master runs 20 to 40MB, so the upload needs longer than a normal
        // request. Everything else here is a small JSON call.
        'upload_timeout' => (int) env('BUNNY_UPLOAD_TIMEOUT', 300),

        // How long a playback URL works for, in seconds. Two hours, decided
        // 29 July 2026: long enough that nobody is cut off mid-video, including
        // a phone leaving wifi for mobile data, and short enough that a copied
        // link is worthless by the evening.
        //
        // The exposure this accepts, in writing: a pass holder can share a
        // working link for that long. IP binding and a fifteen minute token were
        // both offered and declined in favour of never interrupting a viewer.
        'playback_ttl' => (int) env('BUNNY_PLAYBACK_TTL', 7200),

        // How long a homepage reveal URL works for, in seconds. The same two
        // hours, decided 2 August 2026, and deliberately a second key rather
        // than a reference to the one above.
        //
        // They are the same figure and not the same decision. A reveal URL is
        // public and a playback URL is sold, so shortening what anybody can get
        // must never reach into what a paying viewer gets, and lengthening the
        // Viewing Room must never lengthen a public credential. One key each is
        // what keeps moving either one out of the code.
        //
        // What two hours accepts here, in writing: a reveal URL copied out of
        // developer tools plays the full film from anywhere until it expires.
        // Fifteen minutes was offered and declined.
        'reveal_ttl' => (int) env('BUNNY_REVEAL_TTL', 7200),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
