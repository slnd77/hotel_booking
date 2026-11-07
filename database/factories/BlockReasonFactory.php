<?php

namespace Database\Factories;

use App\Models\BlockReason;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BlockReason>
 */
class BlockReasonFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'display_order' => fake()->numberBetween(1, 50),
        ];
    }
}
