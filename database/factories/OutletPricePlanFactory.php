<?php

namespace Database\Factories;

use App\Models\Outlet;
use App\Models\OutletPricePlan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutletPricePlan>
 */
class OutletPricePlanFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outlet_id' => Outlet::factory(),
            'name' => 'Standard Price Plan',
            'start_date' => '1970-01-01',
            'end_date' => '2099-12-31',
            'status' => true,
        ];
    }
}
