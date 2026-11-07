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
        Schema::create('room_upgrades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sub_booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_room_category_id')->constrained('room_categories')->cascadeOnDelete();
            $table->foreignId('to_room_category_id')->constrained('room_categories')->cascadeOnDelete();
            $table->foreignId('from_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            $table->foreignId('to_room_id')->nullable()->constrained('rooms')->nullOnDelete();
            // complimentary or paid
            $table->string('type', 20);
            // What the guest pays on top per night, for the nights from the upgrade to check-out.
            $table->decimal('supplement_per_night', 12, 2)->default(0);
            $table->unsignedSmallInteger('nights')->default(0);
            $table->decimal('rate_before', 12, 2);
            $table->decimal('rate_after', 12, 2);
            $table->string('reason', 255);
            $table->foreignId('upgraded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_upgrades');
    }
};
