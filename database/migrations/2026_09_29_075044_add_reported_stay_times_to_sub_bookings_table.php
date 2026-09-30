<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When each room was actually checked in and out at the front desk.
     */
    public function up(): void
    {
        Schema::table('sub_bookings', function (Blueprint $table) {
            $table->dateTime('checked_in_at')->nullable()->after('status');
            $table->dateTime('checked_out_at')->nullable()->after('checked_in_at');
        });
    }

    public function down(): void
    {
        Schema::table('sub_bookings', function (Blueprint $table) {
            $table->dropColumn(['checked_in_at', 'checked_out_at']);
        });
    }
};
