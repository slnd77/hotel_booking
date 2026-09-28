<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Inventory units are what a stay actually occupies: a whole room, or one bed
     * of a shared (dorm) room. Every existing room becomes one "room" unit.
     */
    public function up(): void
    {
        Schema::create('inventory_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            $table->foreignId('room_category_id')->constrained('room_categories')->cascadeOnDelete();
            // The physical room this unit is, or belongs to (a bed's room).
            $table->foreignId('room_id')->nullable()->constrained('rooms')->cascadeOnDelete();
            $table->string('unit_type')->default('room'); // room | bed
            $table->string('name');
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['room_id', 'unit_type', 'name']);
            $table->index(['property_id', 'room_category_id']);
        });

        $now = now();

        DB::table('rooms')
            ->join('room_categories', 'room_categories.id', '=', 'rooms.room_category_id')
            ->orderBy('rooms.id')
            ->select(['rooms.id', 'rooms.name', 'rooms.room_category_id', 'room_categories.property_id'])
            ->chunk(500, function ($rooms) use ($now): void {
                DB::table('inventory_units')->insert($rooms->map(fn ($room): array => [
                    'property_id' => $room->property_id,
                    'room_category_id' => $room->room_category_id,
                    'room_id' => $room->id,
                    'unit_type' => 'room',
                    'name' => $room->name,
                    'capacity' => 1,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all());
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_units');
    }
};
