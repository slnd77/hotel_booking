<?php

namespace Database\Factories;

use App\Models\Outlet;
use App\Models\OutletItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutletItem>
 */
class OutletItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outlet_id' => Outlet::factory(),
            'outlet_display_category_id' => null,
            'name' => fake()->randomElement(['BreakfastAdult', 'BreakfastChild', 'LunchAdult', 'DinnerAdult', 'Tea', 'Coffee']),
            'tax_category_id' => null,
            'item_type' => 'none',
            'status' => true,
            'display_order' => fake()->numberBetween(1, 20),
        ];
    }
}
