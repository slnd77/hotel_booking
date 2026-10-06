<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agency>
 */
class AgencyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'agent_rate_code_id' => null,
            'source' => Agency::SOURCE_AGENT,
            'name' => strtoupper(fake()->unique()->company()),
            'address' => null,
            'city' => null,
        ];
    }
}
