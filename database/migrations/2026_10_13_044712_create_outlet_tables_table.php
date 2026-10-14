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
        Schema::create('outlet_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('outlet_id')->constrained()->cascadeOnDelete();
            $table->string('name', 20);
            $table->unsignedTinyInteger('seats')->default(4);
            // Where it sits on the floor plan: a section, and its cell on the plan's grid.
            $table->string('area', 60)->nullable();
            $table->unsignedSmallInteger('pos_x')->default(0);
            $table->unsignedSmallInteger('pos_y')->default(0);
            $table->string('shape', 10)->default('square');
            // The random token in the table's ordering QR code.
            $table->string('qr_token', 40)->nullable()->unique();
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->unique(['outlet_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outlet_tables');
    }
};
