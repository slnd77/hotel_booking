<?php

namespace Database\Factories;

use App\Models\ChannexConnection;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ChannexConnection>
 */
class ChannexConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'channex_property_id' => fake()->uuid(),
            'webhook_token' => Str::random(40),
            'is_active' => true,
            'sync_days' => 365,
        ];
    }
}
