<?php

use App\Models\Booking;
use App\Models\Payment;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Booking::with(['guests', 'property'])->chunk(50, function ($bookings) {
            foreach ($bookings as $booking) {
                Payment::logForBooking($booking);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No down migration necessary for backfilled records.
    }
};
