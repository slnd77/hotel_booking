<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each room's housekeeping status (a status name from Booking Settings → Housekeeping)
     * and when it was last marked.
     */
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('housekeeping_status', 64)->nullable()->after('name');
            $table->dateTime('housekeeping_updated_at')->nullable()->after('housekeeping_status');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['housekeeping_status', 'housekeeping_updated_at']);
        });
    }
};
