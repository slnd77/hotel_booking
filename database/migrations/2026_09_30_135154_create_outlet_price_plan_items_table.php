<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An item's price in a price plan (before tax).
     */
    public function up(): void
    {
        Schema::create('outlet_price_plan_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_price_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_item_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 12, 2);
            $table->timestamps();

            $table->unique(['outlet_price_plan_id', 'outlet_item_id'], 'outlet_price_plan_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_price_plan_items');
    }
};
