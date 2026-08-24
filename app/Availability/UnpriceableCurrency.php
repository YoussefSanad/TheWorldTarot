<?php

namespace App\Availability;

use RuntimeException;

/**
 * A currency this system says it sells in, that the products table cannot hold
 * a price for.
 *
 * Settled deliberately, because the two renderings of the pricing rule
 * disagreed about it and neither said so. The query asked Postgres for a column
 * that was not there and died with its error; the predicate read a missing
 * attribute as null, called it zero, and quietly answered that nothing was
 * priced. Between them a currency added to config without its migration either
 * broke the site or emptied it, depending which caller asked.
 *
 * Both now raise this. Adding a currency is a config line and a migration, said
 * plainly in config/currencies.php and in .claude/database.md, and the loud
 * failure is the cheaper half of that pair: a shop with nothing in it is a bug
 * nobody can read, and it would reach production looking like an empty
 * database.
 */
final class UnpriceableCurrency extends RuntimeException
{
    public static function for(string $currency, string $table, string $column): self
    {
        return new self(
            "{$currency} is in config/currencies.php but {$table} has no {$column} column. "
            .'Adding a currency needs a migration adding its column and a price for every existing product.'
        );
    }
}
