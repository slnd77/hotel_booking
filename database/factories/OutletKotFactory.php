<?php

namespace Database\Factories;

use App\Models\OutletBill;
use App\Models\OutletKot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutletKot>
 */
class OutletKotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outlet_bill_id' => OutletBill::factory(),
            'outlet_id' => fn (array $attributes): int => OutletBill::query()->whereKey($attributes['outlet_bill_id'])->firstOrFail()->outlet_id,
            'property_id' => fn (array $attributes): int => OutletBill::query()->whereKey($attributes['outlet_bill_id'])->firstOrFail()->property_id,
            'kot_number' => (string) fake()->unique()->numberBetween(1, 99999),
            'business_date' => now()->toDateString(),
        ];
    }
}
