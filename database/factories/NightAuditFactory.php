<?php

namespace Database\Factories;

use App\Models\NightAudit;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NightAudit>
 */
class NightAuditFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'business_date' => now()->subDay()->toDateString(),
            'status' => NightAudit::COMPLETED,
            'started_at' => now(),
            'finished_at' => now(),
            'summary' => ['report' => [], 'no_shows' => [], 'overstays' => [], 'warnings' => []],
        ];
    }
}
