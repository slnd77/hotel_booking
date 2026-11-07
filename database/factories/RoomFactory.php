<?php

namespace Database\Factories;

use App\Models\Room;
use App\Models\RoomCategory;
use App\Services\DormBeds;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Room>
 */
class RoomFactory extends Factory
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
            'name' => (string) fake()->unique()->numberBetween(100, 9999),
            'display_order' => fake()->numberBetween(0, 10),
        ];
    }

    /**
     * A dorm room with $beds beds (its category must be sold by the bed): one bed unit each.
     */
    public function beds(int $beds = 6): static
    {
        return $this
            ->state(fn (): array => ['bed_count' => $beds])
            ->afterCreating(fn (Room $room) => app(DormBeds::class)->syncRoom($room));
    }
}
