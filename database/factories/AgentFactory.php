<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Agent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'contact_name' => fake()->name(),
            'phone_country_code' => '+91',
            'phone' => fake()->numerify('9#########'),
            'emails' => [],
        ];
    }
}
