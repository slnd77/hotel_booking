<?php

namespace Database\Factories;

use App\Models\ActivityLog;
use App\Models\Guest;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ActivityLog>
 */
class ActivityLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'property_id' => Property::factory(),
            'guest_id' => null,
            'action' => fake()->randomElement(['guest_list_downloaded', 'guest_created', 'guest_updated']),
            'date' => fake()->dateTimeBetween('-30 days', 'now'),
        ];
    }

    public function forGuest(Guest $guest): static
    {
        return $this->state(fn (): array => ['guest_id' => $guest->id]);
    }
}
