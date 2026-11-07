<?php

namespace Database\Factories;

use App\Models\Amenity;
use App\Models\AmenityCategory;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\RoomCategoryAmenity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomCategoryAmenity>
 */
class RoomCategoryAmenityFactory extends Factory
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
            'room_category_id' => RoomCategory::factory(),
            'amenity_id' => Amenity::query()->inRandomOrder()->value('id') ?? Amenity::query()->create([
                'key' => fake()->unique()->slug(2),
                'name' => fake()->words(2, true),
                'amenity_category_id' => AmenityCategory::query()->inRandomOrder()->value('id') ?? AmenityCategory::query()->create([
                    'name' => fake()->unique()->words(2, true),
                    'status' => true,
                ])->id,
                'type' => fake()->randomElement(['property', 'room_category']),
            ])->id,
        ];
    }
}
