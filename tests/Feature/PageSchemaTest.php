<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('stores translatable page columns as jsonb, not json', function () {
    $types = collect(DB::select(
        "select column_name, data_type
         from information_schema.columns
         where table_name = 'pages' and column_name in ('title', 'body')"
    ))->pluck('data_type', 'column_name');

    // json is not usefully indexable on Postgres; the slug expression indexes in
    // later milestones require jsonb. See .claude/translation.md section 3.
    expect($types['title'])->toBe('jsonb')
        ->and($types['body'])->toBe('jsonb');
});
