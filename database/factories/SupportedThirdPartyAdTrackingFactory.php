<?php

namespace Database\Factories;

use App\Models\SupportedThirdPartyAdTracking;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<SupportedThirdPartyAdTracking>
 */
class SupportedThirdPartyAdTrackingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name),
        ];
    }
}
