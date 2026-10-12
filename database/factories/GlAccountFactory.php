<?php

namespace Database\Factories;

use App\Enums\GlAccountType;
use App\Models\GlAccount;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GlAccount>
 */
class GlAccountFactory extends Factory
{
    /**
     * A staff-made expense account.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'code' => (string) fake()->unique()->numberBetween(7000, 7999),
            'name' => ucfirst(fake()->word().' '.fake()->word()),
            'type' => GlAccountType::Expense,
            'is_active' => true,
        ];
    }
}
