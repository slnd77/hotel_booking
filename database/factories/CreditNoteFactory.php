<?php

namespace Database\Factories;

use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Property;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CreditNote>
 */
class CreditNoteFactory extends Factory
{
    /**
     * A credit of 1 180 (1 000 plus 18% tax) on an invoice of the same property.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'invoice_id' => fn (array $attributes): int => Invoice::query()->create([
                'property_id' => $attributes['property_id'],
                'invoice_number' => 'INV'.fake()->unique()->numerify('######'),
                'invoice_generation_date' => now(),
                'invoice_amount' => 5900,
                'taxable_amount' => 5000,
                'total_tax' => 900,
            ])->id,
            'credit_note_number' => 'CN-'.fake()->unique()->numerify('####'),
            'issue_date' => now()->toDateString(),
            'reason' => fake()->sentence(),
            'taxable_amount' => 1000,
            'tax_amount' => 180,
            'total' => 1180,
        ];
    }
}
