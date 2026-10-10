<?php

namespace Database\Factories;

use App\Models\ChartNote;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChartNote>
 */
class ChartNoteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'user_id' => User::factory(),
            'date' => now()->addDays(fake()->numberBetween(0, 10))->toDateString(),
            'body' => fake()->sentence(),
        ];
    }
}
