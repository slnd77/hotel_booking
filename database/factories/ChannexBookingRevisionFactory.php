<?php

namespace Database\Factories;

use App\Models\ChannexBookingRevision;
use App\Models\ChannexConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannexBookingRevision>
 */
class ChannexBookingRevisionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channex_connection_id' => ChannexConnection::factory(),
            'property_id' => fn (array $attributes): int => ChannexConnection::query()->findOrFail($attributes['channex_connection_id'])->property_id,
            'revision_id' => fake()->uuid(),
            'channex_booking_id' => fake()->uuid(),
            'status' => 'new',
            'payload' => [],
        ];
    }
}
