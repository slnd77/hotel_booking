<?php

namespace Database\Factories;

use App\Models\GuestConsent;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GuestConsent>
 */
class GuestConsentFactory extends Factory
{
    /**
     * Privacy policy accepted on the booking engine.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'purpose' => GuestConsent::PRIVACY,
            'granted' => true,
            'policy_version' => now()->toIso8601String(),
            'source' => 'booking_engine',
            'ip_address' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
        ];
    }
}
