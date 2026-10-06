<?php

namespace Database\Factories;

use App\Models\Outlet;
use App\Models\OutletDisplayCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutletDisplayCategory>
 */
class OutletDisplayCategoryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outlet_id' => Outlet::factory(),
            'name' => fake()->randomElement(['Special Items', 'Starters', 'Main Course', 'Beverages', 'Desserts']),
            'display_order' => fake()->numberBetween(1, 10),
        ];
    }
}
