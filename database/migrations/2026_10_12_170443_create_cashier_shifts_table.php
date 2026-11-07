<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A cashier's shift at a property: the cash float it opens with, and at close the cash
     * counted against what the shift's receipts and refunds say should be in the drawer.
     */
    public function up(): void
    {
        Schema::create('cashier_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_float', 12, 2)->default(0);
            $table->decimal('cash_receipts', 12, 2)->nullable();
            $table->decimal('cash_refunds', 12, 2)->nullable();
            $table->decimal('expected_cash', 12, 2)->nullable();
            $table->decimal('cash_counted', 12, 2)->nullable();
            $table->decimal('variance', 12, 2)->nullable();
            $table->json('totals_by_mode')->nullable();
            $table->foreignId('handed_over_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('opening_notes', 500)->nullable();
            $table->string('closing_notes', 500)->nullable();
            $table->timestamps();

            $table->index(['property_id', 'user_id', 'closed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashier_shifts');
    }
};
