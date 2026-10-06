<?php

namespace Database\Factories;

use App\Models\Outlet;
use App\Models\OutletTaxRate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutletTaxRate>
 */
class OutletTaxRateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rate = fake()->randomElement([5, 12, 18]);

        return [
            'outlet_id' => Outlet::factory(),
            'name' => "GST {$rate}%",
            'rate' => $rate,
            'status' => true,
        ];
    }
}
