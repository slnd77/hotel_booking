<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The lines of a POS bill. Name, price and tax rate are copied from the catalogue when the
     * line is added, so later catalogue changes don't rewrite a bill.
     */
    public function up(): void
    {
        Schema::create('outlet_bill_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('sac_hsn_code', 8)->nullable();
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 12, 2);
            $table->decimal('amount', 12, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_bill_items');
    }
};
