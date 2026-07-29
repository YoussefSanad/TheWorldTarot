<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Access to the Viewing Room, recorded on the customer.
     *
     * A timestamp rather than a boolean, deliberately. "Yes" answers whether
     * somebody may watch; a date also answers when they were let in, which is
     * the question support actually gets asked. See CLAUDE.md section 4.
     *
     * Nothing writes it in this milestone. M5 stamps it when the pass is paid
     * for, and everything here reads it.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->timestamp('viewing_room_access_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('viewing_room_access_at');
        });
    }
};
