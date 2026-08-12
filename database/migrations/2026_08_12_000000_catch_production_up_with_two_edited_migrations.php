<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Give production the two schema changes that were edited into migrations
     * it had already run.
     *
     * `fulfilment` became `fulfillment` on 8 August and `password` became
     * nullable on 9 August, both by rewriting the original migration rather
     * than adding a second one. That is free while the only databases are ones
     * that can be thrown away, and it stopped being free the moment production
     * had run those migrations: a file that has already run never runs again,
     * so production kept the old column name and the old NOT NULL, and the
     * product seeder failed on the next deploy trying to write a column that
     * was not there.
     *
     * Every step is conditional, because a database built from scratch already
     * has the shape the edited migrations describe. On a fresh install this
     * migration does nothing and records itself, which is exactly what makes it
     * safe to leave in the history.
     */
    public function up(): void
    {
        if (Schema::hasColumn('products', 'fulfilment') && ! Schema::hasColumn('products', 'fulfillment')) {
            Schema::table('products', function (Blueprint $table) {
                $table->renameColumn('fulfilment', 'fulfillment');
            });
        }

        // An account can exist before anybody has chosen a password for it:
        // buying something creates a customer from the email given at
        // checkout, unclaimed until they set one. The invariant that a row
        // with no password must never authenticate is enforced and tested
        // elsewhere; this only widens the column.
        Schema::table('customers', function (Blueprint $table) {
            $table->string('password')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     *
     * The rename goes back. The NOT NULL does not, and deliberately: by the
     * time this would be rolled back there may be unclaimed customers with no
     * password, and restoring the constraint would either fail outright or
     * demand a guess about what to put in those rows. Leaving the column
     * nullable is the reversal that cannot lose anybody's account.
     */
    public function down(): void
    {
        if (Schema::hasColumn('products', 'fulfillment') && ! Schema::hasColumn('products', 'fulfilment')) {
            Schema::table('products', function (Blueprint $table) {
                $table->renameColumn('fulfillment', 'fulfilment');
            });
        }
    }
};
