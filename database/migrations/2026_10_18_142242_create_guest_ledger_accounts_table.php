<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A guest's ledger at a property: money the property holds for the guest (a credit left by
     * a booking change), kept on its own folio and used on the guest's later bookings.
     */
    public function up(): void
    {
        Schema::create('guest_ledger_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['property_id', 'guest_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_ledger_accounts');
    }
};
