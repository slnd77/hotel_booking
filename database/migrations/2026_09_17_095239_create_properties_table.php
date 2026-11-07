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
        Schema::create('properties', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('property_legal_name');
            $table->foreignId('property_group_id')->constrained('property_groups');
            $table->string('abbreviation');
            $table->integer('property_type');

            $table->boolean('third_party_analytics_enabled')->default(false);
            $table->boolean('third_party_ad_tracking_enabled')->default(false);

            $table->string('property_contact_number')->nullable();
            $table->boolean('outlet_sac_code_enabled')->default(false);

            $table->string('address_line_1');
            $table->string('address_line_2');
            $table->string('postal_code');
            $table->foreignId('city_id')->constrained('cities');
            $table->foreignId('state_id')->constrained('states');
            $table->foreignId('country_id')->constrained('countries');

            $table->boolean('corporate_address_permanent_address_same')->default(false);
            $table->string('corporate_address_line_1');
            $table->string('corporate_address_line_2');
            $table->string('corporate_postal_code');
            $table->foreignId('corporate_city_id')->constrained('cities');
            $table->foreignId('corporate_state_id')->constrained('states');
            $table->foreignId('corporate_country_id')->constrained('countries');

            $table->integer('currency_id');
            $table->boolean('ignore_decimal_places_in_bill_settlement')->default(false);
            $table->decimal('checkout_tolerance_value', 8, 4)->default(0);

            $table->integer('language');

            $table->string('location_map_url')->nullable();
            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->integer('property_segment');

            $table->unsignedTinyInteger('star_rating');
            $table->integer('tax_type');
            $table->boolean('metasearch_engines_listings_enabled')->default(false);

            $table->string('customise_name_for+room_invoice')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('properties');
    }
};
