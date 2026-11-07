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
        Schema::table('room_categories', function (Blueprint $table) {
            // Rooms Setup → "Room category (upsell order)": 1 is the highest-priced category.
            $table->unsignedInteger('upsell_order')->nullable()->after('display_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_categories', function (Blueprint $table) {
            $table->dropColumn('upsell_order');
        });
    }
};
