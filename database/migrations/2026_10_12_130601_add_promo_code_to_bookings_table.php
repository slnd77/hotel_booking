<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The promo code applied at booking engine checkout (its usage limits and budgets
     * count the bookings that carry it).
     */
    public function up(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->string('promo_code', 50)->nullable()->after('discount');
            $table->index(['property_id', 'promo_code']);
        });
    }

    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex(['property_id', 'promo_code']);
            $table->dropColumn('promo_code');
        });
    }
};
