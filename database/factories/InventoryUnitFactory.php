<?php

namespace Database\Factories;

use App\Models\InventoryUnit;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InventoryUnit>
 */
class InventoryUnitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_id' => Room::factory(),
            'room_category_id' => fn (array $attributes): int => Room::query()->findOrFail($attributes['room_id'])->room_category_id,
            'property_id' => fn (array $attributes): int => Room::query()->findOrFail($attributes['room_id'])->roomCategory->property_id,
            'unit_type' => InventoryUnit::TYPE_ROOM,
            'name' => fn (array $attributes): string => Room::query()->findOrFail($attributes['room_id'])->name,
            'capacity' => 1,
            'is_active' => true,
        ];
    }

    /**
     * One bed of a shared (dorm) room.
     */
    public function bed(int $number = 1): static
    {
        return $this->state(fn (): array => ['unit_type' => InventoryUnit::TYPE_BED, 'name' => "Bed {$number}"]);
    }
}
