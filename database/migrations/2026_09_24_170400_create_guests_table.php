<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();

            // Basic details
            $table->string('title')->nullable();
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('phone_country_code')->nullable();
            $table->string('phone')->nullable();
            $table->json('emails')->nullable();
            $table->string('gender')->nullable();
            $table->date('dob')->nullable();
            $table->string('nationality')->nullable();
            $table->string('purpose_of_visit')->nullable();
            $table->text('guest_preferences')->nullable();
            $table->boolean('subscribe_newsletter')->default(false);

            // Other details
            $table->date('anniversary')->nullable();
            $table->string('frequent_flyer')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->string('place_of_birth')->nullable();
            $table->string('present_business_address')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};
