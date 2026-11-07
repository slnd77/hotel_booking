<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('invoice_number')->index();
            $table->string('folio_number')->nullable()->index();
            $table->string('order_number')->nullable();
            $table->dateTime('invoice_generation_date');
            $table->dateTime('reservation_date')->nullable();
            $table->string('outlet_name')->default('Front Office');
            $table->string('unit_type')->default('Room');
            $table->string('status')->default('Bill Generated');
            $table->string('paid_by')->default('Guest');
            $table->string('booking_source')->default('Direct');
            $table->string('guest_name')->nullable();
            $table->string('agent_ota_name')->nullable();
            $table->string('corporate_account')->nullable();
            $table->string('corporate_id')->nullable();
            $table->date('last_receipt_date')->nullable();
            $table->decimal('invoice_amount', 12, 2)->default(0);
            $table->decimal('taxable_amount', 12, 2)->default(0);
            $table->decimal('total_tax', 12, 2)->default(0);
            $table->decimal('amount_paid', 12, 2)->default(0);
            $table->decimal('tax_exempted', 12, 2)->default(0);
            $table->string('tax_exemption_type')->nullable();
            $table->decimal('invoice_outstanding_amount', 12, 2)->default(0);
            $table->string('generated_by_staff')->nullable();
            $table->string('booking_reference')->nullable();
            $table->string('days_in_outstanding')->nullable();
            $table->string('sales_person')->nullable();
            $table->string('promo_name')->nullable();
            $table->string('document_attached')->nullable();
            $table->string('party_name')->nullable();
            $table->string('party_gstin')->nullable();
            $table->date('checkin_date')->nullable();
            $table->date('checkout_date')->nullable();
            $table->integer('total_pax')->default(1);
            $table->integer('total_children')->default(0);
            $table->integer('rooms_count')->default(1);
            $table->decimal('discount', 12, 2)->default(0);
            $table->integer('room_nights')->default(1);
            $table->string('meal_plans')->nullable();
            $table->string('item_types')->nullable();
            $table->string('tally_status')->nullable();
            $table->string('tally_response')->nullable();
            $table->boolean('has_attachment')->default(false);
            $table->string('document')->nullable();
            $table->boolean('send_bill')->default(false);
            $table->boolean('soa_generated')->default(false);
            $table->boolean('show_tax_breakup')->default(false);
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
