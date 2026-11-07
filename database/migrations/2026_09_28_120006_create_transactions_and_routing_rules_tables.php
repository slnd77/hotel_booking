<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The ledger (append-only: entries are never cascade-deleted) and the routing
     * rules that redirect a room's charges to another billing window.
     */
    public function up(): void
    {
        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sub_folio_id')->constrained()->restrictOnDelete();
            $table->string('transaction_type'); // debit (charge) | credit (payment, allowance)
            $table->decimal('amount', 12, 2);
            $table->foreignId('charge_code_id')->constrained()->restrictOnDelete();
            // The room that generated the charge, even when routing posted it elsewhere.
            $table->foreignId('source_sub_booking_id')->nullable()->constrained('sub_bookings')->nullOnDelete();
            // Receipt behind a credit, linking the ledger to the existing payments table.
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('description')->nullable();
            $table->timestamp('posted_at')->useCurrent();
            $table->timestamps();

            $table->index(['sub_folio_id', 'transaction_type']);
        });

        Schema::create('routing_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('source_sub_booking_id')->constrained('sub_bookings')->cascadeOnDelete();
            $table->foreignId('target_sub_folio_id')->constrained('sub_folios')->cascadeOnDelete();
            $table->string('charge_category'); // room_and_tax | fnb | incidentals | all_charges
            $table->timestamps();

            $table->unique(['source_sub_booking_id', 'charge_category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('routing_rules');
        Schema::dropIfExists('transactions');
    }
};
