<?php

namespace Database\Factories;

use App\Enums\SubBookingStatus;
use App\Models\Booking;
use App\Models\InventoryUnit;
use App\Models\SubBooking;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SubBooking>
 */
class SubBookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'booking_id' => fn (array $attributes): int => Booking::query()->create([
                'property_id' => InventoryUnit::query()->findOrFail($attributes['inventory_unit_id'])->property_id,
                'booking_ref_id' => 'BK-'.Str::upper(Str::random(8)),
                'check_in' => now()->addDay()->toDateString(),
                'check_out' => now()->addDays(3)->toDateString(),
            ])->id,
            'inventory_unit_id' => InventoryUnit::factory(),
            'room_category_id' => fn (array $attributes): int => InventoryUnit::query()->findOrFail($attributes['inventory_unit_id'])->room_category_id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(3)->toDateString(),
            'status' => SubBookingStatus::Booked,
            'meal_plan' => 'EP',
            'adults' => 2,
            'children' => 0,
            'infants' => 0,
        ];
    }
}
