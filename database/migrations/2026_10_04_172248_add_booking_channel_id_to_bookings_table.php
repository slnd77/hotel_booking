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
        Schema::table('bookings', function (Blueprint $table) {
            $table->foreignId('booking_channel_id')->nullable()->after('agent_id')->constrained()->nullOnDelete();
            $table->foreignId('metasearch_platform_id')->nullable()->after('booking_channel_id')->constrained('supported_metasearch_platforms')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('metasearch_platform_id');
            $table->dropConstrainedForeignId('booking_channel_id');
        });
    }
};
