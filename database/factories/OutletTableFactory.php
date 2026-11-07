<?php

namespace Database\Factories;

use App\Models\Outlet;
use App\Models\OutletTable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutletTable>
 */
class OutletTableFactory extends Factory
{
    /**
     * A four-seat table of an outlet, at the top left of its floor plan.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outlet_id' => Outlet::factory(),
            'property_id' => fn (array $attributes): int => Outlet::query()->findOrFail($attributes['outlet_id'])->property_id,
            'name' => 'T'.fake()->unique()->numberBetween(1, 999),
            'seats' => 4,
            'area' => null,
            'pos_x' => 0,
            'pos_y' => 0,
            'shape' => 'square',
            'status' => true,
        ];
    }
}
