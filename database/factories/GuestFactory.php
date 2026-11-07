<?php

namespace Database\Factories;

use App\Models\Guest;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Guest>
 */
class GuestFactory extends Factory
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
            'title' => fake()->randomElement(['Mr', 'Mrs', 'Ms', 'Dr']),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone_country_code' => '+91',
            'phone' => fake()->numerify('9#########'),
            'emails' => [fake()->safeEmail()],
            'gender' => fake()->randomElement(['Male', 'Female', 'Other']),
            'dob' => fake()->dateTimeBetween('-60 years', '-18 years')->format('Y-m-d'),
            'nationality' => 'India',
            'purpose_of_visit' => fake()->randomElement(['Leisure', 'Business']),
            'guest_preferences' => fake()->optional()->sentence(),
            'subscribe_newsletter' => fake()->boolean(),
            'anniversary' => null,
            'frequent_flyer' => null,
            'vehicle_number' => null,
            'place_of_birth' => fake()->city(),
            'present_business_address' => null,
            'address' => null,
            'company' => null,
        ];
    }
}
