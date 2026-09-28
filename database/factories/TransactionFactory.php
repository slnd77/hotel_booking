<?php

namespace Database\Factories;

use App\Enums\TransactionType;
use App\Models\ChargeCode;
use App\Models\SubFolio;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Transaction>
 */
class TransactionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sub_folio_id' => SubFolio::factory(),
            'transaction_type' => TransactionType::Debit,
            'amount' => 1000,
            'charge_code_id' => ChargeCode::factory(),
            'posted_at' => now(),
        ];
    }
}
