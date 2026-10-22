<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * CLAUDE.md hard rule 5: never store money as a float. Money is integer minor
 * units (see `App\Support\Money`). docs/plans/arch-tests.md, step 5.
 *
 * Reads the migrated schema, not migration text, so raw `DB::statement` SQL is
 * caught as well as `$table->float()`. A future non-money `numeric` column
 * (a ratio, say) trips this on purpose: whoever adds it must add a commented
 * exclusion here, which is the review moment wanted. PHP-level floats (casts)
 * are not checked.
 *
 * @return list<string> `table.column (type)` for every offending column
 */
function floatingPointColumnsInPublicSchema(): array
{
    $rows = DB::select(
        "select table_name, column_name, data_type from information_schema.columns
         where table_schema = current_schema()
           and lower(data_type) in ('real', 'double precision', 'numeric', 'money')
         order by table_name, ordinal_position",
    );

    return array_map(
        fn (object $row): string => "{$row->table_name}.{$row->column_name} ({$row->data_type})",
        $rows,
    );
}

it('has no real, double precision, numeric or money column in the current (public) schema', function () {
    expect(floatingPointColumnsInPublicSchema())->toBe([]);
});

// Guards against a vacuous pass: an empty or wrong schema would also be "empty".
it('is looking at the real migrated schema, where money is an integer', function () {
    $tables = collect(DB::select(
        'select table_name from information_schema.tables where table_schema = current_schema()',
    ))->pluck('table_name');

    expect($tables)->toContain('orders')->toContain('products');

    $total = DB::selectOne(
        "select data_type from information_schema.columns
         where table_schema = current_schema() and table_name = 'orders' and column_name = 'total_amount'",
    );

    expect($total)->not->toBeNull()
        ->and(strtolower($total->data_type))->toBeIn(['integer', 'bigint']);
});

// Self-test: the query must actually catch each forbidden type, or the first
// test is decoration. Postgres DDL is transactional and RefreshDatabase wraps
// each test, so these tables vanish.
it('catches every forbidden column type when one is added', function () {
    DB::statement('create table arch_probe_money (
        a real, b double precision, c numeric(10, 2), d money, e integer, f bigint
    )');

    // Only the probe's own entries, so a real violation elsewhere fails the
    // main test alone and not this one.
    $probe = array_values(array_filter(
        floatingPointColumnsInPublicSchema(),
        fn (string $found): bool => str_starts_with($found, 'arch_probe_money.'),
    ));

    expect($probe)->toBe([
        'arch_probe_money.a (real)',
        'arch_probe_money.b (double precision)',
        'arch_probe_money.c (numeric)',
        'arch_probe_money.d (money)',
    ]);
});

it('catches a column a float migration adds to an existing table', function () {
    DB::statement('alter table orders add column arch_probe double precision null');

    expect(floatingPointColumnsInPublicSchema())->toContain('orders.arch_probe (double precision)');
});
