<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\ReportDelivery;
use App\Services\DailyFrontOfficeReport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportDelivery>
 */
class ReportDeliveryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'report' => DailyFrontOfficeReport::REPORT,
            'report_date' => now()->subDay()->toDateString(),
            'recipients' => [fake()->safeEmail()],
            'status' => ReportDelivery::STATUS_QUEUED,
        ];
    }

    public function scheduled(): static
    {
        return $this->state(fn (array $attributes): array => ['scheduled_for' => now()->toDateString()]);
    }
}
