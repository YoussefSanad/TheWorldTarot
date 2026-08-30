<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | The Next.js frontend authenticates with cookie-based Sanctum sessions,
    | which only works when the API returns CORS headers that allow the
    | frontend origin WITH credentials. `supports_credentials` must stay true
    | and the origin list must be explicit (a wildcard origin is not allowed
    | alongside credentials). Origins are env-driven so each environment sets
    | its own. See .claude/api.md and CLAUDE.md section 3.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(
        explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000'))
    ),

    'allowed_origins_patterns' => array_filter(
        explode(',', env('CORS_ALLOWED_ORIGINS_PATTERNS', ''))
    ),

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Retry-After'],

    'max_age' => 0,

    'supports_credentials' => true,
];
