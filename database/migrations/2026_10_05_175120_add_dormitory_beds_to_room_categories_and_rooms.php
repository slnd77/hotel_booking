<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Dormitories: a category sold by the bed instead of by the room, and how many beds
     * each of its rooms has (each bed is then its own inventory unit).
     */
    public function up(): void
    {
        Schema::table('room_categories', function (Blueprint $table) {
            $table->boolean('sell_by_bed')->default(false)->after('exclude_from_upgrade');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedSmallInteger('bed_count')->nullable()->after('display_order');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('bed_count');
        });

        Schema::table('room_categories', function (Blueprint $table) {
            $table->dropColumn('sell_by_bed');
        });
    }
};
