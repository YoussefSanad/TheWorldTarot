<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Converts between what Jennifer types and what the database stores.
 *
 * She types 19.00, the database holds 1900. That conversion is the only place
 * in this system where money is arithmetic, so it is done here, once, and
 * without floats: 19.99 * 100 is 1998.9999999999998 in binary floating point,
 * and rounding that quietly is how a penny goes missing. Hard rule 5.
 */
final class Money
{
    /**
     * "19.99" to 1999. Accepts a comma as the decimal separator, since a
     * European keyboard produces one without thinking about it.
     */
    public static function toMinor(string $amount): int
    {
        $normalised = str_replace(',', '.', trim($amount));

        if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/', $normalised, $matches)) {
            throw new InvalidArgumentException("[{$amount}] is not an amount like 19.00.");
        }

        $major = (int) $matches[1];

        // "19.5" means 19.50, not 19.05, so the decimals are padded rather than
        // read as-is.
        $minor = (int) str_pad($matches[2] ?? '0', 2, '0');

        return $major * 100 + $minor;
    }

    /**
     * 1999 to "19.99". Always two decimal places, so the form never shows a
     * price as "19.9".
     */
    public static function toMajor(int $amount): string
    {
        return sprintf('%d.%02d', intdiv($amount, 100), $amount % 100);
    }
}
