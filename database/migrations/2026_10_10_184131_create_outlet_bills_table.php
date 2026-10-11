<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Point-of-sale bills of an outlet: open while items are added, then posted to a guest's
     * folio (charged to the room), settled with a payment, or voided.
     */
    public function up(): void
    {
        Schema::create('outlet_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->restrictOnDelete();
            $table->string('bill_number');
            // The property's business date the sale belongs to (not the UTC day).
            $table->date('business_date');
            $table->string('status')->default('open'); // open | posted | settled | void
            $table->string('guest_name')->nullable();
            // Posted to a room: the booking, the room and the billing window charged.
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sub_booking_id')->nullable()->constrained('sub_bookings')->nullOnDelete();
            $table->foreignId('sub_folio_id')->nullable()->constrained('sub_folios')->nullOnDelete();
            // The receipt that paid the bill (settled at the outlet, or paid when posting to a room).
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('sub_total', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'bill_number']);
            $table->index(['property_id', 'business_date']);
            $table->index(['outlet_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_bills');
    }
};
