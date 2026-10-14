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
        Schema::create('stay_restrictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            // Null: every room category of the property.
            $table->foreignId('room_category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->date('date');
            // Applied to a stay arriving on the date.
            $table->unsignedSmallInteger('min_stay')->nullable();
            $table->unsignedSmallInteger('max_stay')->nullable();
            $table->boolean('closed_to_arrival')->default(false);
            $table->boolean('closed_to_departure')->default(false);
            // No stay may include the night.
            $table->boolean('stop_sell')->default(false);
            $table->timestamps();

            $table->index(['property_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stay_restrictions');
    }
};
