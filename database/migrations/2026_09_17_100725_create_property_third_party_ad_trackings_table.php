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
        Schema::create('property_third_party_ad_trackings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->foreignId('supported_third_party_ad_tracking_id')
                ->constrained('supported_third_party_ad_trackings', 'id', 'property_ad_tracking_id_foreign')
                ->cascadeOnDelete();
            $table->string('tracking_id')->nullable();
            $table->timestamps();

            $table->unique(['property_id', 'supported_third_party_ad_tracking_id'], 'property_ad_tracking_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('property_third_party_ad_trackings');
    }
};
