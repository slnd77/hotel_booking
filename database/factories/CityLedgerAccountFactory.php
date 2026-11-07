<?php

namespace Database\Factories;

use App\Models\CityLedgerAccount;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CityLedgerAccount>
 */
class CityLedgerAccountFactory extends Factory
{
    /**
     * A company on 30-day terms with a 50 000 credit limit.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'name' => fake()->company(),
            'code' => strtoupper(fake()->unique()->lexify('CL???')),
            'account_type' => 'company',
            'email' => fake()->companyEmail(),
            'credit_limit' => 50000,
            'payment_terms_days' => 30,
            'is_active' => true,
        ];
    }
}
