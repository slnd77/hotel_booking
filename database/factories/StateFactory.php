<?php

namespace Database\Factories;

use App\Models\Country;
use App\Models\State;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<State>
 */
class StateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->format('state'),
            'country_id' => Country::query()->inRandomOrder()->value('id') ?? Country::query()->create([
                'name' => fake()->unique()->country(),
                'iso3' => strtoupper(fake()->unique()->lexify('???')),
                'iso2' => strtoupper(fake()->unique()->lexify('??')),
            ])->id,
            'state_code' => strtoupper(fake()->lexify('??')),
            'type' => fake()->randomElement(['state', 'province', 'region']),
            'latitude' => fake()->latitude(),
            'longitude' => fake()->longitude(),
        ];
    }
}
