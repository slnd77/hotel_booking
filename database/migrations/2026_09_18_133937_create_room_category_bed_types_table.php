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
        Schema::create('room_category_bed_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_category_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('type');
            $table->unsignedInteger('quantity')->nullable();
            $table->timestamps();

            $table->unique(['room_category_id', 'name', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('room_category_bed_types');
    }
};
