<?php

namespace Tests\Support;

/**
 * The tables a gifting fixture writes, and nothing else.
 *
 * `Illuminate\Foundation\Testing\DatabaseTruncation` empties every table it can
 * find unless it is given a list, and a test that races two real processes at
 * one row has to commit rather than roll back, so it is the only trait that can
 * hold the database still for it.
 *
 * **An allowlist rather than `$exceptTables`, and the difference is not
 * cosmetic.** `locales` is written by a migration and not by a seeder, so
 * nothing anywhere puts a row back once it is truncated: the first race would
 * empty the language registry and every later test in the process would then
 * be running against an application with no languages. An allowlist cannot
 * grow that fault by accident, since a table added to the schema is not
 * truncated until somebody names it here.
 *
 * Postgres truncates with `cascade`, so anything referencing one of these goes
 * with it. Every referencing table today is already on the list.
 */
trait TruncatesWhatAGiftTouches
{
    /**
     * @var array<int, string>
     */
    protected array $tablesToTruncate = [
        'customers',
        'gifts',
        'order_lines',
        'orders',
        'products',
        'readings',
    ];
}
