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
