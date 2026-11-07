<?php

namespace Database\Seeders;

use App\Models\MetaField;
use App\Models\Property;
use Illuminate\Database\Seeder;

class TaxCategorySeeder extends Seeder
{
    /**
     * @var array<int, array{name: string, description: string}>
     */
    private const DEFAULT_CATEGORIES = [
        ['name' => 'Room', 'description' => 'Room Type'],
        ['name' => 'Meal', 'description' => 'Meal Type'],
        ['name' => 'Package', 'description' => 'Package Type'],
        ['name' => 'Receipt - Default', 'description' => 'Receipt - Default Type'],
        ['name' => 'Cancellation - Default', 'description' => 'Cancellation - Default Type'],
    ];

    public function run(): void
    {
        Property::query()->each(fn (Property $property) => self::applyDefaultsTo($property));
    }

    /**
     * Merge the default tax categories into a single property's
     * `tax_settings_tax_category` meta field, skipping names that already exist.
     * Shared by this seeder and PropertyFactory so both stay in sync.
     */
    public static function applyDefaultsTo(Property $property): void
    {
        $metaField = MetaField::query()->where('key', 'tax_settings_tax_category')->first();

        if (! $metaField) {
            return;
        }

        $propertyMetaField = $property->metaFieldValues()
            ->where('meta_fields_key_id', $metaField->id)
            ->first();

        $data = $propertyMetaField && $propertyMetaField->data
            ? json_decode($propertyMetaField->data, true)
            : ['categories' => []];

        $categories = $data['categories'] ?? [];
        $existingNames = collect($categories)->pluck('name')->all();
        $nextId = collect($categories)->max('id') ?? 0;

        foreach (self::DEFAULT_CATEGORIES as $default) {
            if (in_array($default['name'], $existingNames, true)) {
                continue;
            }

            $categories[] = [
                'id' => ++$nextId,
                'name' => $default['name'],
                'description' => $default['description'],
                'is_default' => true,
                'deleted' => false,
            ];
        }

        $data['categories'] = $categories;

        $property->metaFieldValues()->updateOrCreate(
            ['meta_fields_key_id' => $metaField->id],
            ['enabled' => true, 'data' => json_encode($data)],
        );
    }
}
