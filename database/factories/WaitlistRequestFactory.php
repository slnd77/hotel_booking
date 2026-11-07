<?php

namespace Database\Factories;

use App\Enums\WaitlistStatus;
use App\Models\Property;
use App\Models\WaitlistRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WaitlistRequest>
 */
class WaitlistRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'room_category_id' => null,
            'guest_name' => fake()->name(),
            'phone' => fake()->numerify('9#########'),
            'email' => fake()->safeEmail(),
            'check_in' => now()->addDays(7)->toDateString(),
            'check_out' => now()->addDays(9)->toDateString(),
            'rooms' => 1,
            'adults' => 2,
            'children' => 0,
            'source' => WaitlistRequest::SOURCE_FRONT_DESK,
            'status' => WaitlistStatus::Waiting,
        ];
    }
}
