<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Channel manager (Channex): a property's link to its Channex property, which room
     * categories and rate rows map to Channex room types and rate plans, and the booking
     * revisions (new / modified / cancelled OTA bookings) received from it.
     */
    public function up(): void
    {
        Schema::create('channex_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('channex_property_id');
            // Optional per-property key; null uses CHANNEX_API_KEY.
            $table->text('api_key')->nullable();
            // Secret part of the webhook URL Channex calls on new bookings.
            $table->string('webhook_token', 64)->unique();
            $table->uuid('channex_webhook_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sync_days')->default(365);
            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('last_pulled_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('channex_room_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channex_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_category_id')->constrained()->cascadeOnDelete();
            $table->uuid('channex_room_type_id');
            $table->timestamps();

            $table->unique(['channex_connection_id', 'room_category_id'], 'channex_room_mappings_category_unique');
            $table->unique(['channex_connection_id', 'channex_room_type_id'], 'channex_room_mappings_room_type_unique');
        });

        Schema::create('channex_rate_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channex_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_category_id')->constrained()->cascadeOnDelete();
            $table->string('meal_plan', 10);
            // Rate row priced for this many adults (Single = 1, Double = 2, …).
            $table->unsignedTinyInteger('adults')->default(2);
            $table->uuid('channex_rate_plan_id');
            $table->timestamps();

            $table->unique(['channex_connection_id', 'channex_rate_plan_id'], 'channex_rate_mappings_rate_plan_unique');
        });

        Schema::create('channex_booking_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channex_connection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->uuid('revision_id')->unique();
            $table->uuid('channex_booking_id')->index();
            $table->string('status', 20); // new | modified | cancelled
            $table->string('ota_name')->nullable();
            $table->string('ota_reservation_code')->nullable();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channex_booking_revisions');
        Schema::dropIfExists('channex_rate_mappings');
        Schema::dropIfExists('channex_room_mappings');
        Schema::dropIfExists('channex_connections');
    }
};
