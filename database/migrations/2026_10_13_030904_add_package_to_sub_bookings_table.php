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
        Schema::table('sub_bookings', function (Blueprint $table) {
            // The Rate Settings package the room was sold under (its id in rate_settings_packages).
            $table->unsignedInteger('package_id')->nullable()->after('meal_plan');
            $table->string('package_name')->nullable()->after('package_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sub_bookings', function (Blueprint $table) {
            $table->dropColumn(['package_id', 'package_name']);
        });
    }
};
