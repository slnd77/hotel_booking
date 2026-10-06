<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An outlet's catalogue items. The tax category is one of the property's Tax Settings
     * → Tax Categories (kept there as JSON, so it's referenced by its id there).
     */
    public function up(): void
    {
        Schema::create('outlet_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_display_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->unsignedInteger('tax_category_id')->nullable();
            $table->string('item_type')->default('none');
            $table->boolean('status')->default(true);
            $table->unsignedInteger('display_order')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outlet_items');
    }
};
