<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained('properties')->cascadeOnDelete();
            $table->string('booking_ref_id');
            $table->string('source')->default('direct');
            $table->string('type')->default('walk_in');
            $table->string('booking_type')->nullable();
            $table->date('check_in');
            $table->date('check_out');
            $table->string('check_in_time')->nullable();
            $table->string('check_out_time')->nullable();
            $table->boolean('same_plan_for_all_rooms')->default(true);
            $table->boolean('pay_at_hotel')->default(false);
            $table->string('rate_plan')->nullable();
            $table->string('check_in_status')->nullable();
            $table->string('status')->default('confirmed');
            $table->decimal('room_total', 12, 2)->default(0);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('taxes', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('outstanding', 12, 2)->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
