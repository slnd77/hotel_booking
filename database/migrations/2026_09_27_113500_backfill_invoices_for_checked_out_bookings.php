<?php

use App\Models\Booking;
use App\Models\Invoice;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $checkedOutBookings = Booking::query()
            ->where('status', 'checked_out')
            ->with(['property', 'guests', 'rooms.roomCategory', 'payments'])
            ->get();

        foreach ($checkedOutBookings as $booking) {
            Invoice::generateForBooking($booking, 'System');
        }
    }

    public function down(): void
    {
        // No-op
    }
};
