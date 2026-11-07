<?php

namespace Database\Factories;

use App\Models\Property;
use App\Models\Room;
use App\Models\RoomCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RoomCategory>
 */
class RoomCategoryFactory extends Factory
{
    /**
     * Curated distinct images for hotel room categories.
     * Each room category gets a different image.
     */
    public const ROOM_CATEGORY_IMAGES = [
        'Single Room' => 'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=800&q=80',
        'Double Room' => 'https://images.unsplash.com/photo-1590490360182-c33d57733427?auto=format&fit=crop&w=800&q=80',
        'Twin Room' => 'https://images.unsplash.com/photo-1566665797739-1674de7a421a?auto=format&fit=crop&w=800&q=80',
        'Triple Room' => 'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=800&q=80',
        'Queen Room' => 'https://images.unsplash.com/photo-1618773928121-c32242e63f39?auto=format&fit=crop&w=800&q=80',
        'King Room' => 'https://images.unsplash.com/photo-1591088398332-8a7791972843?auto=format&fit=crop&w=800&q=80',
        'Studio Room' => 'https://images.unsplash.com/photo-1595526114035-0d45ed16cfbf?auto=format&fit=crop&w=800&q=80',
        'Suite' => 'https://images.unsplash.com/photo-1578683010236-d716f9a3f461?auto=format&fit=crop&w=800&q=80',
        'Junior Suite' => 'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?auto=format&fit=crop&w=800&q=80',
        'Deluxe Room' => 'https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=800&q=80',
        'Executive Room' => 'https://images.unsplash.com/photo-1582719508461-905c673771fd?auto=format&fit=crop&w=800&q=80',
        'Accessible Room' => 'https://images.unsplash.com/photo-1616046229478-9901c5536a45?auto=format&fit=crop&w=800&q=80',
        'Standard Room' => 'https://images.unsplash.com/photo-1596394516093-501ba68a0ba6?auto=format&fit=crop&w=800&q=80',
        'Super Deluxe Room' => 'https://images.unsplash.com/photo-1587985064135-0366536eab42?auto=format&fit=crop&w=800&q=80',
    ];

    /**
     * Fallback list of diverse hotel room images ensuring uniqueness by category.
     */
    public const FALLBACK_IMAGES = [
        'https://images.unsplash.com/photo-1505693416388-ac5ce068fe85?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1590490360182-c33d57733427?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1566665797739-1674de7a421a?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1582719478250-c89cae4dc85b?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1618773928121-c32242e63f39?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1591088398332-8a7791972843?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1595526114035-0d45ed16cfbf?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1578683010236-d716f9a3f461?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1631049307264-da0ec9d70304?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1611892440504-42a792e24d32?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1582719508461-905c673771fd?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1616046229478-9901c5536a45?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1596394516093-501ba68a0ba6?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1587985064135-0366536eab42?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1522771739844-6a9f6d5f14af?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1560448204-e02f11c3d0e2?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1590490359854-dfba19688d70?auto=format&fit=crop&w=800&q=80',
        'https://images.unsplash.com/photo-1631049552057-403cdb8f0658?auto=format&fit=crop&w=800&q=80',
    ];

    /**
     * The short form of a category name: the initials of its words ("Super Deluxe Room" →
     * "SDR"); numbers are left out.
     */
    public static function abbreviationFor(string $name): string
    {
        preg_match_all('/(?<![\p{L}\p{N}])\p{L}/u', $name, $initials);

        return mb_strtoupper(implode('', $initials[0])) ?: mb_strtoupper(mb_substr($name, 0, 3));
    }

    public static function imageForCategory(RoomCategory $roomCategory): string
    {
        foreach (self::ROOM_CATEGORY_IMAGES as $prefix => $url) {
            if (stripos($roomCategory->name, $prefix) !== false) {
                return $url;
            }
        }

        $index = abs((int) ($roomCategory->id ?? $roomCategory->display_order ?? crc32($roomCategory->name))) % count(self::FALLBACK_IMAGES);

        return self::FALLBACK_IMAGES[$index];
    }

    public static function imagesForCategory(RoomCategory $roomCategory, int $count = 4): array
    {
        $primary = self::imageForCategory($roomCategory);
        $images = [$primary];

        $baseIndex = abs((int) ($roomCategory->id ?? $roomCategory->display_order ?? crc32($roomCategory->name)));
        for ($i = 0; $i < count(self::FALLBACK_IMAGES) && count($images) < $count; $i++) {
            $fallback = self::FALLBACK_IMAGES[($baseIndex + $i + 1) % count(self::FALLBACK_IMAGES)];
            if (! in_array($fallback, $images, true)) {
                $images[] = $fallback;
            }
        }

        return $images;
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (RoomCategory $roomCategory): void {
            if ($roomCategory->images()->count() === 0) {
                $images = self::imagesForCategory($roomCategory, 4);
                foreach ($images as $index => $imagePath) {
                    $roomCategory->images()->create([
                        'path' => $imagePath,
                        'selected' => $index === 0,
                    ]);
                }
            }
        });
    }

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->randomElement(['Standard', 'Deluxe', 'Super Deluxe', 'Executive', 'Suite']).' Room '.fake()->unique()->numberBetween(1, 1000000);

        return [
            'property_id' => Property::factory(),
            'name' => $name,
            'description' => fake()->sentence(),
            // Follows the final name, also when the caller overrides it ("Single Room" → "SR").
            'abbreviation' => fn (array $attributes): string => self::abbreviationFor((string) $attributes['name']),
            'integration_code' => strtoupper(fake()->bothify('##??')),
            'inclusion_details' => fake()->paragraph(),
            'display_order' => fake()->numberBetween(0, 10),
            'base_occupancy' => 2,
            'ideal_occupancy' => 2,
            'max_adults' => 5,
            'max_children' => 2,
            'default_number_of_children' => 0,
            'total_number_of_guests' => 5,
            'sync_category_on_ota' => true,
            'exclude_from_revenue' => false,
            'exclude_from_occupancy' => false,
            'exclude_from_upgrade' => false,
            'meal_plans' => ['EP', 'CP'],
        ];
    }

    /**
     * A dormitory: sold by the bed, one adult per bed, with $rooms dorm rooms of $beds
     * beds each ("Dorm 1", "Dorm 2"…), every bed its own inventory unit.
     */
    public function dormitory(int $rooms = 2, int $beds = 6): static
    {
        return $this
            ->state(fn (): array => [
                'name' => 'Mixed Dormitory '.fake()->unique()->numberBetween(1, 1000000),
                'description' => 'Shared dormitory, sold by the bed.',
                'sell_by_bed' => true,
                'base_occupancy' => 1,
                'ideal_occupancy' => 1,
                'max_adults' => 1,
                'max_children' => 0,
                'default_number_of_children' => 0,
                'total_number_of_guests' => 1,
                'meal_plans' => ['EP'],
            ])
            ->afterCreating(function (RoomCategory $roomCategory) use ($rooms, $beds): void {
                foreach (range(1, $rooms) as $number) {
                    Room::factory()->beds($beds)->create([
                        'room_category_id' => $roomCategory->id,
                        'name' => "Dorm {$number}",
                        'display_order' => $number,
                    ]);
                }
            });
    }
}
