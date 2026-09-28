<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * While booking_rooms is the source of truth, deleting a legacy room row deletes
     * its mirrored sub-booking (the edit form bulk-deletes and recreates rooms, which
     * fires no model events). Mirrored sub-bookings never lose their link.
     */
    public function up(): void
    {
        Schema::table('sub_bookings', function (Blueprint $table) {
            $table->dropForeign(['booking_room_id']);
            $table->foreign('booking_room_id')->references('id')->on('booking_rooms')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sub_bookings', function (Blueprint $table) {
            $table->dropForeign(['booking_room_id']);
            $table->foreign('booking_room_id')->references('id')->on('booking_rooms')->nullOnDelete();
        });
    }
};
