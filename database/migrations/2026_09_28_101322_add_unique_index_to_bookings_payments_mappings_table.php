<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A payment is tagged to a booking once. Duplicate rows (which made a receipt
     * show twice on the booking page) are removed first, keeping the oldest.
     */
    public function up(): void
    {
        $keepIds = DB::table('bookings_payments_mappings')
            ->selectRaw('MIN(id) as id')
            ->groupBy('booking_id', 'payment_id')
            ->pluck('id');

        DB::table('bookings_payments_mappings')->whereNotIn('id', $keepIds)->delete();

        Schema::table('bookings_payments_mappings', function (Blueprint $table) {
            $table->unique(['booking_id', 'payment_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings_payments_mappings', function (Blueprint $table) {
            $table->dropUnique(['booking_id', 'payment_id']);
        });
    }
};
