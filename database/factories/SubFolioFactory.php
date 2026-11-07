<?php

namespace Database\Factories;

use App\Models\Folio;
use App\Models\SubFolio;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubFolio>
 */
class SubFolioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'folio_id' => Folio::factory(),
            'window_number' => 1,
            'window_name' => 'Window A',
        ];
    }
}
