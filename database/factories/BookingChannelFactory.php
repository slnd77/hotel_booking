<?php

namespace Database\Factories;

use App\Models\BookingChannel;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BookingChannel>
 */
class BookingChannelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'is_active' => true,
        ];
    }
}
