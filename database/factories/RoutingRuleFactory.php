<?php

namespace Database\Factories;

use App\Enums\ChargeCategory;
use App\Models\RoutingRule;
use App\Models\SubBooking;
use App\Models\SubFolio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoutingRule>
 */
class RoutingRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_sub_booking_id' => SubBooking::factory(),
            'target_sub_folio_id' => SubFolio::factory(),
            'charge_category' => ChargeCategory::RoomAndTax,
        ];
    }
}
