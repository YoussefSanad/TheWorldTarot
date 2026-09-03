<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Not everything in the catalogue can be bought for somebody else.
     *
     * **A column beside `allows_question`, and for the same reasons.** Both are
     * structure rather than value: code owns them, the seeder re-applies them
     * on every run from `ProductKey`, and neither is editable in the panel. The
     * frontend draws its `GIFT A READING` control off this exactly as it draws
     * the question field off `allows_question`, so a product that grows or
     * loses the ability is one seeder run away rather than a frontend release.
     *
     * **The two fields are independent and `one-card` is what proves it**: it
     * takes a question and cannot be gifted. Anything deriving either from the
     * other draws the wrong control on that product.
     *
     * **Defaults to false**, so a product that predates this is not silently
     * giftable in the window between the migration and the seeder. Nothing is
     * given away by accident; the seeder is what turns it on.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_giftable')->default(false)->after('allows_question');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_giftable');
        });
    }
};
