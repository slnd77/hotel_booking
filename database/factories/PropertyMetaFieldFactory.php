<?php

namespace Database\Factories;

use App\Models\MetaField;
use App\Models\Property;
use App\Models\PropertyMetaField;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PropertyMetaField>
 */
class PropertyMetaFieldFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'property_id' => Property::factory(),
            'meta_fields_key_id' => MetaField::query()->inRandomOrder()->value('id') ?? MetaField::query()->create([
                'key' => fake()->unique()->slug(2),
                'key_label' => fake()->words(2, true),
                'status' => true,
            ])->id,
            'enabled' => true,
            'data' => json_encode(['value' => fake()->word()]),
        ];
    }
}
