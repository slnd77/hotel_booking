<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_number',
                'refund_amount',
                'commission_amount',
                'tax_on_commission',
                'agent_ota_name',
                'net_payment_to_property',
                'tagged_amount',
                'untagged_amount',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('invoice_number')->nullable();
            $table->decimal('net_payment_to_property', 12, 2)->default(0);
            $table->decimal('tagged_amount', 12, 2)->default(0);
            $table->decimal('untagged_amount', 12, 2)->default(0);
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->decimal('tax_on_commission', 12, 2)->default(0);
            $table->string('agent_ota_name')->nullable();
        });
    }
};
