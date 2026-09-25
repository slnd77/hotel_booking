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
        Schema::create('property_meta_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('meta_fields_key_id')
                ->constrained('meta_fields', 'id', 'property_meta_fields_key_id_foreign')
                ->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->longText('data')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'meta_fields_key_id'], 'property_meta_fields_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_meta_fields');
    }
};
