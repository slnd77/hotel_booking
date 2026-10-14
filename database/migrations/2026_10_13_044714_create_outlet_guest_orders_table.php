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
        Schema::create('outlet_guest_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_table_id')->nullable()->constrained()->nullOnDelete();
            // pending, accepted, rejected
            $table->string('status', 20)->default('pending');
            $table->string('guest_name', 100)->nullable();
            $table->text('notes')->nullable();
            // [{outlet_item_id, name, quantity, unit_price}] as priced when ordered.
            $table->json('items');
            $table->decimal('total', 12, 2)->default(0);
            $table->foreignId('outlet_bill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->string('reject_reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outlet_guest_orders');
    }
};
