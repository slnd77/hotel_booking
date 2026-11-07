<?php

namespace Database\Factories;

use App\Enums\OutletBillStatus;
use App\Models\Outlet;
use App\Models\OutletBill;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutletBill>
 */
class OutletBillFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outlet_id' => Outlet::factory(),
            'property_id' => fn (array $attributes): int => Outlet::query()->findOrFail($attributes['outlet_id'])->property_id,
            'bill_number' => (string) fake()->unique()->numberBetween(1, 99999),
            'business_date' => now()->toDateString(),
            'status' => OutletBillStatus::Open,
        ];
    }
}
