<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default currency
    |--------------------------------------------------------------------------
    |
    | Served when a request supplies no currency and the country is unknown or
    | maps to nothing. Also the fallback for Cloudflare's non-country codes,
    | such as XX for unknown and T1 for Tor exit nodes.
    |
    */

    'default' => 'USD',

    /*
    |--------------------------------------------------------------------------
    | Available currencies
    |--------------------------------------------------------------------------
    |
    | Prices are fixed per currency and typed in by hand. Adding a currency here
    | is not enough on its own: prices live as NOT NULL columns on `products`,
    | so a new currency also needs a migration adding its column and a price for
    | every existing product. That cost is deliberate, see .claude/database.md.
    | There is no base price and no exchange rate anywhere in this system.
    |
    */

    'available' => [
        'USD' => ['symbol' => '$', 'label' => 'US Dollar'],
        'EUR' => ['symbol' => '€', 'label' => 'Euro'],
        'GBP' => ['symbol' => '£', 'label' => 'Pound Sterling'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Country to currency
    |--------------------------------------------------------------------------
    |
    | Keyed by the ISO 3166-1 alpha-2 code Cloudflare sends in CF-IPCountry.
    | Any country absent from this list falls back to the default.
    |
    | All 27 EU member states map to EUR, per CLAUDE.md section 4, including
    | the ones that do not use the euro domestically (Sweden, Poland, Denmark,
    | Czechia, Hungary, Romania, Bulgaria). That is a deliberate commercial
    | simplification, not an oversight: it keeps the currency list to three.
    | A Swedish customer sees euro prices and can override the choice.
    |
    */

    'countries' => [
        // United Kingdom
        'GB' => 'GBP',

        // European Union
        'AT' => 'EUR',
        'BE' => 'EUR',
        'BG' => 'EUR',
        'HR' => 'EUR',
        'CY' => 'EUR',
        'CZ' => 'EUR',
        'DK' => 'EUR',
        'EE' => 'EUR',
        'FI' => 'EUR',
        'FR' => 'EUR',
        'DE' => 'EUR',
        'GR' => 'EUR',
        'HU' => 'EUR',
        'IE' => 'EUR',
        'IT' => 'EUR',
        'LV' => 'EUR',
        'LT' => 'EUR',
        'LU' => 'EUR',
        'MT' => 'EUR',
        'NL' => 'EUR',
        'PL' => 'EUR',
        'PT' => 'EUR',
        'RO' => 'EUR',
        'SK' => 'EUR',
        'SI' => 'EUR',
        'ES' => 'EUR',
        'SE' => 'EUR',
    ],

];
