<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A property's add-on services (airport pickup, breakfast, spa…) sold with a booking:
     * priced per booking, per night, per guest or per guest per night, with their own tax.
     */
    public function up(): void
    {
        Schema::create('addons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('pricing')->default('per_booking'); // per_booking | per_night | per_guest | per_guest_per_night
            $table->decimal('price', 12, 2);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('sac_hsn_code', 8)->nullable();
            $table->boolean('show_on_booking_engine')->default(true);
            $table->boolean('status')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['property_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('addons');
    }
};
