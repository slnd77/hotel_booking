<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Waitlist: hold requests for sold-out dates, converted into bookings when inventory frees up.
     */
    public function up(): void
    {
        Schema::create('waitlist_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            // Null: any room category will do.
            $table->foreignId('room_category_id')->nullable()->constrained('room_categories')->nullOnDelete();
            $table->foreignId('guest_id')->nullable()->constrained()->nullOnDelete();
            // The booking the request was converted into.
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->string('guest_name');
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->date('check_in');
            $table->date('check_out');
            $table->unsignedSmallInteger('rooms')->default(1);
            $table->unsignedSmallInteger('adults')->default(2);
            $table->unsignedSmallInteger('children')->default(0);
            $table->text('notes')->nullable();
            // front_desk or booking_engine
            $table->string('source', 20)->default('front_desk');
            // waiting, available, converted, expired, cancelled
            $table->string('status', 20)->default('waiting');
            // When inventory last freed up for the request.
            $table->timestamp('available_since')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['property_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('waitlist_requests');
    }
};
