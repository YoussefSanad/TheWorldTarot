<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An order that does not know whose it is yet.
     *
     * **The original column said "never null. Every order belongs to
     * somebody", and that was true while the buyer's email arrived before the
     * order did.** From 29 August 2026 checkout happens on Stripe's hosted
     * page, which collects the email after the order exists, so there is a
     * window where nobody owns the order. See
     * `docs/adr/0002-the-redirect-case-comes-back.md`.
     *
     * **Null means nobody owns this yet, and it is not a state anything is
     * fulfilled in.** Identity is filled at settlement from the Checkout
     * Session, before `MarkOrderPaid` runs, so an order that is paid still
     * always has a customer. What null covers is a pending order, and an
     * abandoned one that will stay pending forever.
     *
     * The foreign key is rebuilt rather than altered because that is the only
     * way Postgres will take the change, and `restrictOnDelete` is carried
     * over unchanged: deleting a customer who has bought something must still
     * fail loudly rather than quietly destroy the record of a payment.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
        });
    }

    /**
     * **Refuses to run while any order is unowned**, rather than inventing a
     * customer for it or deleting the record of a purchase. If this needs to
     * go back, the orders holding nobody have to be dealt with by a person
     * first, and there is no honest automatic answer to what should happen to
     * them.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['customer_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable(false)->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
        });
    }
};
