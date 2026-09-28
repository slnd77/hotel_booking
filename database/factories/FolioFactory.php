<?php

namespace Database\Factories;

use App\Enums\FolioStatus;
use App\Models\Folio;
use App\Models\SubBooking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Folio>
 */
class FolioFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_type' => (new SubBooking)->getMorphClass(),
            'owner_id' => SubBooking::factory(),
            'status' => FolioStatus::Open,
            'currency' => 'INR',
        ];
    }
}
