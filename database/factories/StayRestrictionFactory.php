<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\StayRestriction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StayRestriction>
 */
class StayRestrictionFactory extends Factory
{
    /**
     * A two-night minimum stay on a property-wide date.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'room_category_id' => null,
            'date' => now()->addWeek()->toDateString(),
            'min_stay' => 2,
            'max_stay' => null,
            'closed_to_arrival' => false,
            'closed_to_departure' => false,
            'stop_sell' => false,
        ];
    }

    public function stopSell(): static
    {
        return $this->state(['min_stay' => null, 'stop_sell' => true]);
    }
}
