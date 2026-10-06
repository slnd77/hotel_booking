<?php

namespace Database\Factories;

use App\Enums\OutletType;
use App\Models\Outlet;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Outlet>
 */
class OutletFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => fake()->randomElement(['Meal', 'Restaurant', 'Laundry', 'Spa', 'Minibar']),
            'type' => fake()->randomElement(OutletType::cases()),
            'printer_setting' => 'a4',
            'display_order' => fake()->numberBetween(1, 10),
            'status' => true,
        ];
    }
}
