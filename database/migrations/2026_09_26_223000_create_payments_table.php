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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type')->default('receipt')->index();
            $table->timestamp('payment_creation_date')->nullable();
            $table->timestamp('payment_transaction_date')->nullable();
            $table->string('voucher_number')->nullable()->index();
            $table->string('invoice_number')->nullable();
            $table->string('receipt_number')->nullable()->index();
            $table->string('status')->default('Received')->index();
            $table->string('payment_ref_id')->nullable();
            $table->string('pg_txn_id')->nullable();
            $table->string('ota_booking_ref_id')->nullable();
            $table->string('neft_ref_id')->nullable();
            $table->string('imps_ref_id')->nullable();
            $table->string('rtgs_ref_id')->nullable();
            $table->string('guest_name')->nullable();
            $table->string('payment_mode')->nullable();
            $table->string('card_number')->nullable();
            $table->decimal('total_txn_amount', 12, 2)->default(0);
            $table->decimal('net_payment_to_property', 12, 2)->default(0);
            $table->decimal('tagged_amount', 12, 2)->default(0);
            $table->decimal('untagged_amount', 12, 2)->default(0);
            $table->decimal('refund_amount', 12, 2)->default(0);
            $table->decimal('commission_amount', 12, 2)->default(0);
            $table->decimal('tax_on_commission', 12, 2)->default(0);
            $table->string('collection_source')->nullable();
            $table->string('card_name')->nullable();
            $table->string('bank')->nullable();
            $table->string('booking_source')->nullable();
            $table->string('agent_ota_name')->nullable();
            $table->string('delete_status')->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->string('collected_by_staff')->nullable();
            $table->text('notes')->nullable();
            $table->string('pg_pay_fields_edited')->nullable();
            $table->string('coupon')->nullable();
            $table->string('payee_name')->nullable();
            $table->string('document')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
