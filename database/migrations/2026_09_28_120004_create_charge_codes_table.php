<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Chart of accounts for ledger entries. The category decides which routing rules apply.
     */
    public function up(): void
    {
        Schema::create('charge_codes', function (Blueprint $table) {
            $table->id();
            // Null = shared by every property.
            $table->foreignId('property_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('code', 30);
            $table->string('name');
            $table->string('category'); // room_and_tax | fnb | incidentals | payment
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['property_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_codes');
    }
};
