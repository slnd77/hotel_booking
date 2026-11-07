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
            $table->unsignedInteger('display_order')->default(0)->after('property_id');
            $table->string('description')->nullable()->after('name');
            $table->string('abbreviation')->nullable()->after('description');
            $table->string('integration_code')->nullable()->after('abbreviation');
            $table->longText('inclusion_details')->nullable()->after('integration_code');

            $table->unsignedInteger('base_occupancy')->default(1)->after('inclusion_details');
            $table->unsignedInteger('ideal_occupancy')->default(1)->after('base_occupancy');
            $table->unsignedInteger('max_adults')->default(1)->after('ideal_occupancy');
            $table->unsignedInteger('max_children')->default(0)->after('max_adults');
            $table->unsignedInteger('default_number_of_children')->default(0)->after('max_children');
            $table->unsignedInteger('total_number_of_guests')->default(1)->after('default_number_of_children');

            $table->boolean('sync_category_on_ota')->default(true)->after('total_number_of_guests');
            $table->boolean('exclude_from_revenue')->default(false)->after('sync_category_on_ota');
            $table->boolean('exclude_from_occupancy')->default(false)->after('exclude_from_revenue');
            $table->boolean('exclude_from_upgrade')->default(false)->after('exclude_from_occupancy');

            $table->json('meal_plans')->nullable()->after('exclude_from_upgrade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('room_categories', function (Blueprint $table) {
            $table->dropColumn([
                'display_order',
                'description',
                'abbreviation',
                'integration_code',
                'inclusion_details',
                'base_occupancy',
                'ideal_occupancy',
                'max_adults',
                'max_children',
                'default_number_of_children',
                'total_number_of_guests',
                'sync_category_on_ota',
                'exclude_from_revenue',
                'exclude_from_occupancy',
                'exclude_from_upgrade',
                'meal_plans',
            ]);
        });
    }
};
