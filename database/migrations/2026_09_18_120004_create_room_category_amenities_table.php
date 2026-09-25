<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_category_amenities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('room_category_id')->constrained('room_categories')->cascadeOnDelete();
            $table->foreignId('amenity_id')->constrained('amenities')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['property_id', 'room_category_id', 'amenity_id'], 'room_category_amenities_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_category_amenities');
    }
};
