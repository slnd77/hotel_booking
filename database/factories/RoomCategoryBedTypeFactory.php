<?php

namespace Database\Factories;

use App\Models\RoomCategory;
use App\Models\RoomCategoryBedType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomCategoryBedType>
 */
class RoomCategoryBedTypeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'room_category_id' => RoomCategory::factory(),
            'name' => fake()->randomElement(['Single', 'Double', 'Queen', 'King', 'Twin']),
            'type' => fake()->randomElement(['bed', 'extra_bed']),
            'quantity' => fake()->numberBetween(1, 3),
        ];
    }
}
