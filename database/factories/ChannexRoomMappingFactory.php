<?php

namespace Database\Factories;

use App\Models\ChannexConnection;
use App\Models\ChannexRoomMapping;
use App\Models\RoomCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannexRoomMapping>
 */
class ChannexRoomMappingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channex_connection_id' => ChannexConnection::factory(),
            'room_category_id' => RoomCategory::factory(),
            'channex_room_type_id' => fake()->uuid(),
        ];
    }
}
