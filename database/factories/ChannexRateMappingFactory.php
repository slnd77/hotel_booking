<?php

namespace Database\Factories;

use App\Models\ChannexConnection;
use App\Models\ChannexRateMapping;
use App\Models\RoomCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannexRateMapping>
 */
class ChannexRateMappingFactory extends Factory
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
            'meal_plan' => 'EP',
            'adults' => 2,
            'channex_rate_plan_id' => fake()->uuid(),
        ];
    }
}
