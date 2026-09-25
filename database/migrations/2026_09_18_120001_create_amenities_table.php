<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('amenities', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('name');
            $table->foreignId('amenity_category_id')->constrained('amenity_categories')->cascadeOnDelete();
            $table->string('type')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('amenities');
    }
};
