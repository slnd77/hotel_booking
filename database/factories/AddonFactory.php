<?php

namespace Database\Factories;

use App\Models\Addon;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Addon>
 */
class AddonFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => fake()->randomElement(['Airport pickup', 'Breakfast', 'Spa session', 'Late check-out']),
            'pricing' => 'per_booking',
            'price' => 500,
            'tax_rate' => 0,
            'show_on_booking_engine' => true,
            'status' => true,
        ];
    }
}
