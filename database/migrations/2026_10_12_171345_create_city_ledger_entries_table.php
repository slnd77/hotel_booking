<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A city ledger account's entries: charges (bills transferred from bookings) raise what it
     * owes, payments and credit adjustments lower it. Amounts are signed: + charge, − credit.
     */
    public function up(): void
    {
        Schema::create('city_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_ledger_account_id')->constrained()->cascadeOnDelete();
            $table->string('entry_type', 20);
            $table->date('entry_date');
            $table->date('due_date')->nullable();
            $table->decimal('amount', 12, 2);
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference')->nullable();
            $table->string('description', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['city_ledger_account_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_ledger_entries');
    }
};
