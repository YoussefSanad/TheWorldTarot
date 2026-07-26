<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default locale
    |--------------------------------------------------------------------------
    |
    | The locale served when no valid locale is supplied on a request, and the
    | fallback for the safety net in translation.md section 7.
    |
    */

    'default' => 'en',

    /*
    |--------------------------------------------------------------------------
    | Available locales
    |--------------------------------------------------------------------------
    |
    | This list declares what the system CAN serve. It is the developer gate:
    | a locale appears here only once its lang/ files exist in both this repo
    | and the frontend repo. Whether an available locale is actually served,
    | and to whom, is decided by the `locales` table (available/preview/live).
    |
    | Config always wins. A `locales` row whose code is absent from this list
    | is treated as if it does not exist, regardless of its state.
    |
    | Adding a locale here must never require a database migration.
    |
    */

    'available' => [
        'en' => ['label' => 'English', 'native' => 'English'],
        'es' => ['label' => 'Spanish', 'native' => 'Español'],
    ],

];
