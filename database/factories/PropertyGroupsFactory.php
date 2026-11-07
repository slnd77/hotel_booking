<?php

namespace Database\Factories;

use App\Models\PropertyGroups;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyGroups>
 */
class PropertyGroupsFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company().' Group',
        ];
    }
}
