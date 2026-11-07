<?php

namespace Database\Factories;

use App\Models\MetaField;
use App\Models\Property;
use Carbon\CarbonImmutable;

/**
 * Builds a `tax_settings_tax_rule` JSON payload (matching the shape the Tax
 * Settings > Tax Rule tab reads/writes) — one CGST + SGST rule per tax category
 * already seeded on the property.
 *
 * There is no TaxRule Eloquent model — tax rules are stored as a JSON blob on
 * `property_meta_fields`, so this is a plain builder rather than an Eloquent factory.
 */
class TaxRuleFactory
{
    /**
     * Slab breakup shared by both the CGST and SGST components, taken from a real
     * A typical slab: 0-999 at 0%, 999.01-7500 at 2.5%, 7500.01+ at 9%.
     *
     * @var array<int, array{start_range: float, end_range: float, tax: float}>
     */
    private const SLABS = [
        ['start_range' => 0, 'end_range' => 999, 'tax' => 0],
        ['start_range' => 999.01, 'end_range' => 7500, 'tax' => 2.5],
        ['start_range' => 7500.01, 'end_range' => 999999999, 'tax' => 9],
    ];

    /**
     * @return array{component_choices: array<int, array<string, mixed>>, rules: array<int, array<string, mixed>>}
     */
    public static function make(Property $property): array
    {
        $categories = self::taxCategories($property);

        $startDate = CarbonImmutable::now()->startOfDay();
        $endDate = $startDate->addYears(2);

        $rules = [];

        foreach ($categories as $index => $category) {
            $rules[] = [
                'id' => $index + 1,
                'tax_category_id' => $category['id'],
                'name' => "{$category['name']} Tax Rule",
                'description' => '',
                'start_date' => $startDate->toDateString(),
                'end_date' => $endDate->toDateString(),
                'components' => [
                    self::component(1, 'CGST'),
                    self::component(2, 'SGST'),
                ],
                'deleted' => false,
            ];
        }

        return [
            'component_choices' => [
                ['id' => 1, 'name' => 'Default Tax Choice', 'description' => ''],
                ['id' => 2, 'name' => 'CGST', 'description' => ''],
                ['id' => 3, 'name' => 'SGST', 'description' => ''],
            ],
            'rules' => $rules,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function component(int $id, string $choice): array
    {
        return [
            'id' => $id,
            'component_choice' => $choice,
            'applied_on' => 'discounted_net_rate',
            'calculated_on' => 'per_room_per_room_night',
            'tax_value_type' => 'percentage',
            'tax_value' => 0,
            'define_tax_slab' => true,
            'define_los_slab' => false,
            'slabs' => collect(self::SLABS)->map(fn (array $slab, int $index) => [
                'id' => $index + 1,
                'start_range' => $slab['start_range'],
                'end_range' => $slab['end_range'],
                'tax_value_type' => 'percentage',
                'tax' => $slab['tax'],
            ])->all(),
        ];
    }

    /**
     * @return array<int, array{id: int, name: string}>
     */
    private static function taxCategories(Property $property): array
    {
        $metaField = MetaField::query()->where('key', 'tax_settings_tax_category')->first();

        if (! $metaField) {
            return [];
        }

        $propertyMetaField = $property->metaFieldValues()
            ->where('meta_fields_key_id', $metaField->id)
            ->first();

        if (! $propertyMetaField || ! $propertyMetaField->data) {
            return [];
        }

        $data = json_decode($propertyMetaField->data, true);

        return $data['categories'] ?? [];
    }
}
