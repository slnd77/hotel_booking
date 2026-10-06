<?php

namespace Database\Factories;

use App\Models\AgentRateCode;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentRateCode>
 */
class AgentRateCodeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => 'Agent'.fake()->unique()->numberBetween(1, 999),
            'code' => null,
            'description' => null,
            'is_active' => true,
        ];
    }
}
