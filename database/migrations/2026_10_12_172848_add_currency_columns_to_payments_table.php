<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A payment made in another currency: the currency, the amount in it, and the rate it was
     * converted at (total_txn_amount stays in the property's currency).
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('currency_code', 3)->nullable()->after('total_txn_amount');
            $table->decimal('currency_amount', 12, 2)->nullable()->after('currency_code');
            $table->decimal('exchange_rate', 18, 6)->nullable()->after('currency_amount');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['currency_code', 'currency_amount', 'exchange_rate']);
        });
    }
};
