<?php

namespace Database\Factories;

use App\Enums\PaymentLinkStatus;
use App\Models\Booking;
use App\Models\PaymentLink;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentLink>
 */
class PaymentLinkFactory extends Factory
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
            'booking_id' => fn (array $attributes): int => Booking::query()->create([
                'property_id' => $attributes['property_id'],
                'booking_ref_id' => 'BK-'.Str::upper(Str::random(8)),
                'check_in' => now()->addDays(2)->toDateString(),
                'check_out' => now()->addDays(4)->toDateString(),
                'total' => 5000,
                'outstanding' => 5000,
            ])->id,
            'collect_for' => 'Guest',
            'amount' => 2500,
            'currency' => 'INR',
            'email' => fake()->safeEmail(),
            'mobile' => '+91'.fake()->numerify('9#########'),
            'status' => PaymentLinkStatus::Pending,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subHour()]);
    }

    public function paid(): static
    {
        return $this->state(fn (): array => ['status' => PaymentLinkStatus::Paid, 'paid_at' => now()]);
    }
}
