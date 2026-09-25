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
        Schema::create('property_meta_search_platform', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('supported_metasearch_platform_id')
                ->constrained('supported_metasearch_platforms', 'id', 'property_metasearch_platform_id_foreign')
                ->cascadeOnDelete();
            $table->string('listing_id')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'supported_metasearch_platform_id'], 'property_metasearch_platform_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_meta_search_platform');
    }
};
