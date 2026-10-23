<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Postgres does not index the referencing side of a foreign key, so every
     * join and parent delete on these columns was a table scan (R7).
     *
     * Plain CREATE INDEX takes a write lock for its duration. Acceptable here
     * because these tables hold tens of rows; do not copy onto a large table.
     */
    private const INDEXES = [
        'orders' => ['customer_id'],
        'order_lines' => ['order_id', 'product_id'],
        'readings' => ['order_line_id', 'gift_id'],
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column) {
                    $table->index($column);
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::INDEXES as $table => $columns) {
            Schema::table($table, function (Blueprint $table) use ($columns) {
                foreach ($columns as $column) {
                    $table->dropIndex([$column]);
                }
            });
        }
    }
};
