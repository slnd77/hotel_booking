<?php

namespace Database\Factories;

use App\Models\CashierShift;
use App\Models\Property;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CashierShift>
 */
class CashierShiftFactory extends Factory
{
    /**
     * An open shift with a 2 000 float.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'user_id' => User::factory(),
            'opened_at' => now(),
            'opening_float' => 2000,
        ];
    }

    /**
     * Closed with the cash it should have had.
     */
    public function closed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'closed_at' => now(),
            'cash_receipts' => 0,
            'cash_refunds' => 0,
            'expected_cash' => $attributes['opening_float'],
            'cash_counted' => $attributes['opening_float'],
            'variance' => 0,
            'totals_by_mode' => [],
        ]);
    }
}
