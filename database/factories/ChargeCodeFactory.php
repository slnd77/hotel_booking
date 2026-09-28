<?php

namespace Database\Factories;

use App\Enums\ChargeCategory;
use App\Models\ChargeCode;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChargeCode>
 */
class ChargeCodeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => null,
            'code' => fake()->unique()->bothify('CODE-###'),
            'name' => 'Room Charge',
            'category' => ChargeCategory::RoomAndTax,
            'is_active' => true,
        ];
    }

    public function category(ChargeCategory $category): static
    {
        return $this->state(fn (): array => ['category' => $category, 'name' => str($category->value)->headline()->toString()]);
    }
}
