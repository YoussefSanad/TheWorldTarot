<?php

namespace App\Support;

/**
 * Turns a country code into a currency, and validates a supplied one.
 *
 * Detection is a fallback, never a decision. When a request states its currency
 * explicitly, that value is honoured and this class is not asked to detect
 * anything. Silently re-detecting over a customer's own choice reads as a bug
 * on a product page and as a bait and switch at checkout.
 *
 * No conversion happens here or anywhere else. See CLAUDE.md section 4.
 */
final class CurrencyResolver
{
    public static function default(): string
    {
        return config('currencies.default');
    }

    /**
     * Every currency this system sells in.
     *
     * Here rather than read from config at each call site, so this class stays
     * the one authority on the question and a validation rule cannot come to a
     * different answer than `isSupported` below.
     *
     * @return array<int, string>
     */
    public static function supported(): array
    {
        return array_keys(config('currencies.available'));
    }

    /**
     * Whether a currency is one this system sells in. Case insensitive, since
     * the value can arrive from a query string or a cookie.
     */
    public static function isSupported(?string $code): bool
    {
        if ($code === null || $code === '') {
            return false;
        }

        return array_key_exists(strtoupper($code), config('currencies.available'));
    }

    /**
     * The currency for a country code, falling back to the default for unknown
     * countries and for Cloudflare's non-country values such as XX and T1.
     */
    public static function forCountry(?string $countryCode): string
    {
        if ($countryCode === null || $countryCode === '') {
            return self::default();
        }

        $currency = config('currencies.countries')[strtoupper($countryCode)] ?? null;

        // A mapped currency that is no longer in the available list must not be
        // served. Falling back is safer than selling in a currency with no
        // prices behind it.
        return self::isSupported($currency) ? $currency : self::default();
    }

    /**
     * Resolve the currency for a request. An explicit, supported currency wins
     * outright. Anything else detects from the country.
     */
    public static function resolve(?string $explicit, ?string $countryCode): string
    {
        if (self::isSupported($explicit)) {
            return strtoupper($explicit);
        }

        return self::forCountry($countryCode);
    }
}
