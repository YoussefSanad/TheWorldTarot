<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A customer nobody ever asked the name of.
     *
     * **The column was NOT NULL because an account was made from a form we
     * wrote**, and that form asks for a name. Since ADR 0002 the road a
     * customer actually takes is Stripe's hosted page, and the account is made
     * at settlement from whatever Stripe collected. On the wallet road that can
     * be an address and nothing else, because the element is mounted asking for
     * an email and not for a billing contact.
     *
     * **So the column was never really NOT NULL, it was NOT NULL and lied**:
     * `OrderController` wrote `''` into it, and `BuyerIdentity` invented a name
     * out of the part of the address before the `@`, which is how a real
     * customer came to be greeted "Hello jennifer,". Both were working around
     * this constraint rather than satisfying it. Issue #52.
     *
     * **Null means nobody has ever told us**, and `App\Support\Greeting` is
     * what every message asks rather than interpolating the column.
     *
     * The empty strings already in the table are converted with it, because
     * leaving two spellings of "no name" behind is how the next reader finds a
     * `?: ''` somewhere and puts one of them back.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('name')->nullable()->change();
        });

        DB::table('customers')->whereRaw("trim(name) = ''")->update(['name' => null]);
    }

    /**
     * **Empty string is what a nameless customer becomes on the way back**, not
     * a name invented for them, and not a deleted row.
     *
     * **This does not restore what the old code would have written, and cannot.**
     * Before today a settlement buyer with no name was stored as the part of
     * their address before the `@`, so an honest reversal would have to invent
     * `jennifer` again, which is the defect this migration exists to remove.
     * Rolling back the code as well as the schema therefore leaves these rows
     * reading "Hello ," in a receipt, which is the older defect underneath it.
     * Said out loud rather than discovered, because both directions land
     * somewhere the old code was not written against and there is no value
     * here that avoids it.
     */
    public function down(): void
    {
        DB::table('customers')->whereNull('name')->update(['name' => '']);

        Schema::table('customers', function (Blueprint $table) {
            $table->string('name')->nullable(false)->change();
        });
    }
};
