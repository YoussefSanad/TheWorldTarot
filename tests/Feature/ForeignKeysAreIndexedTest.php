<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * docs/plans/design-review-fixes.md, step 1 (R7): every foreign key has an
 * index, because Postgres does not create one on the referencing side and an
 * unindexed FK turns every parent delete and every join into a table scan.
 *
 * Reads the migrated schema, not migration text, so raw SQL is covered too.
 *
 * Multi-column FKs: only the FK's first column is checked, and an index led by
 * that column counts whatever columns follow it. An FK (a, b) with an index
 * (b, a) is therefore flagged, on purpose: the lookup by `a` and the
 * parent-delete check cannot use it well. Partial indexes do not count, since
 * they do not cover every row. Only btree counts, since a BRIN or GIN index
 * does not serve an equality lookup the way the FK needs. The `indisvalid`
 * filter drops a half-built index; it has no test, because an invalid index
 * needs CREATE INDEX CONCURRENTLY, which cannot run inside the transaction
 * RefreshDatabase wraps each test in.
 *
 * @return list<string> `table.column` for every FK whose leading column has no index
 */
function unindexedForeignKeyColumns(): array
{
    $rows = DB::select(
        "select distinct cls.relname as table_name, att.attname as column_name
         from pg_constraint con
         join pg_class cls on cls.oid = con.conrelid
         join pg_namespace ns on ns.oid = cls.relnamespace
         join pg_attribute att on att.attrelid = con.conrelid and att.attnum = con.conkey[1]
         where con.contype = 'f'
           and ns.nspname = 'public'
           and not exists (
               select 1 from pg_index idx
               join pg_class icls on icls.oid = idx.indexrelid
               join pg_am am on am.oid = icls.relam
               where idx.indrelid = con.conrelid
                 and idx.indisvalid
                 and idx.indpred is null
                 and am.amname = 'btree'
                 and idx.indkey[0] = con.conkey[1]
           )
         order by cls.relname, att.attname",
    );

    return array_map(
        fn (object $row): string => "{$row->table_name}.{$row->column_name}",
        $rows,
    );
}

/**
 * Every FK's leading column in the public schema, indexed or not. Used only to
 * prove the main query is looking at the real schema.
 *
 * @return list<string> `table.column`
 */
function allForeignKeyColumns(): array
{
    $rows = DB::select(
        "select distinct cls.relname as table_name, att.attname as column_name
         from pg_constraint con
         join pg_class cls on cls.oid = con.conrelid
         join pg_namespace ns on ns.oid = cls.relnamespace
         join pg_attribute att on att.attrelid = con.conrelid and att.attnum = con.conkey[1]
         where con.contype = 'f' and ns.nspname = 'public'",
    );

    return array_map(
        fn (object $row): string => "{$row->table_name}.{$row->column_name}",
        $rows,
    );
}

it('has an index led by every foreign key column in the public schema', function () {
    $missing = unindexedForeignKeyColumns();

    // Naming each column in the message is the point: a bare count tells a
    // reader nothing about which migration to write.
    expect($missing)->toBe(
        [],
        'Foreign key columns with no index: '.implode(', ', $missing),
    );
});

// Guards against a vacuous pass: a wrong schema would also have no violations.
// Guards the public-schema scoping: the foreign keys the migrations declare
// must all be seen, so a wrong schema cannot pass by finding nothing. Extend
// the list when a migration adds a foreign key worth naming here.
it('sees the foreign keys the migrations declare', function () {
    expect(allForeignKeyColumns())->toContain(
        'orders.customer_id',
        'order_lines.order_id',
        'order_lines.product_id',
        'readings.order_line_id',
        'readings.gift_id',
        'gifts.order_line_id',
    );
});

// Self-tests: the query must catch what it claims to, or the first test is
// decoration. Postgres DDL is transactional and RefreshDatabase wraps each
// test, so these tables vanish.
it('catches an unindexed foreign key and clears once it is indexed', function () {
    DB::statement('create table fk_probe_parent (id bigint primary key)');
    DB::statement('create table fk_probe_child (
        id bigint primary key, parent_id bigint references fk_probe_parent (id)
    )');

    expect(unindexedForeignKeyColumns())->toContain('fk_probe_child.parent_id');

    DB::statement('create index fk_probe_child_parent_id_index on fk_probe_child (parent_id)');

    expect(unindexedForeignKeyColumns())->not->toContain('fk_probe_child.parent_id');
});

it('requires the foreign key column to lead the index, not merely appear in it', function () {
    DB::statement('create table fk_probe_parent (id bigint primary key)');
    DB::statement('create table fk_probe_child (
        id bigint primary key, other bigint, parent_id bigint references fk_probe_parent (id)
    )');
    DB::statement('create index fk_probe_child_other_parent_index on fk_probe_child (other, parent_id)');

    expect(unindexedForeignKeyColumns())->toContain('fk_probe_child.parent_id');
});

it('does not count a partial index as covering a foreign key', function () {
    DB::statement('create table fk_probe_parent (id bigint primary key)');
    DB::statement('create table fk_probe_child (
        id bigint primary key, parent_id bigint references fk_probe_parent (id)
    )');
    DB::statement('create index fk_probe_child_partial on fk_probe_child (parent_id) where parent_id is not null');

    expect(unindexedForeignKeyColumns())->toContain('fk_probe_child.parent_id');
});

it('does not count a BRIN index as covering a foreign key', function () {
    DB::statement('create table fk_probe_parent (id bigint primary key)');
    DB::statement('create table fk_probe_child (
        id bigint primary key, parent_id bigint references fk_probe_parent (id)
    )');
    // BRIN on a bigint needs no extension.
    DB::statement('create index fk_probe_child_brin on fk_probe_child using brin (parent_id)');

    expect(unindexedForeignKeyColumns())->toContain('fk_probe_child.parent_id');
});

it('flags a composite foreign key whose only index leads with a different column', function () {
    DB::statement('create table fk_probe_parent (a bigint, b bigint, primary key (a, b))');
    DB::statement('create table fk_probe_child (
        id bigint primary key, a bigint, b bigint,
        foreign key (a, b) references fk_probe_parent (a, b)
    )');
    DB::statement('create index fk_probe_child_b_a_index on fk_probe_child (b, a)');

    expect(unindexedForeignKeyColumns())->toContain('fk_probe_child.a');
});

it('accepts a composite foreign key whose leading column leads an index', function () {
    DB::statement('create table fk_probe_parent (a bigint, b bigint, primary key (a, b))');
    DB::statement('create table fk_probe_child (
        id bigint primary key, a bigint, b bigint,
        foreign key (a, b) references fk_probe_parent (a, b)
    )');

    expect(unindexedForeignKeyColumns())->toContain('fk_probe_child.a');

    DB::statement('create index fk_probe_child_a_b_index on fk_probe_child (a, b)');

    expect(unindexedForeignKeyColumns())->not->toContain('fk_probe_child.a');
});
