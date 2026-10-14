<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Early check-in and late check-out charges are booking add-on lines for one room (added
     * from "early_check_in" / "late_check_out"): undoing that room's check-in or check-out
     * removes them.
     */
    public function up(): void
    {
        Schema::table('booking_addons', function (Blueprint $table) {
            $table->foreignId('sub_booking_id')->nullable()->after('addon_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('booking_addons', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sub_booking_id');
        });
    }
};
