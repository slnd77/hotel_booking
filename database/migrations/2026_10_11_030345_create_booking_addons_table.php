<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add-ons sold on a booking. Name, price, pricing and tax are copied from the catalogue
     * when added, so catalogue changes don't rewrite a booking. Removed lines are soft
     * deleted: the ledger sync reverses what they charged.
     */
    public function up(): void
    {
        Schema::create('booking_addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('addon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('pricing');
            $table->decimal('unit_price', 12, 2);
            $table->unsignedInteger('quantity')->default(1);
            // Nights, guests or both, as the pricing counts them when the line was priced.
            $table->unsignedInteger('multiplier')->default(1);
            $table->decimal('amount', 12, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_amount', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->string('sac_hsn_code', 8)->nullable();
            $table->string('added_from')->default('front_desk'); // front_desk | booking_engine | api
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_addons');
    }
};
