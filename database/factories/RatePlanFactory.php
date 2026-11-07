<?php

namespace Database\Factories;

use App\Models\Property;

/**
 * Builds a `rate_settings_rate_plan` JSON payload (matching the shape the Rate
 * Settings > Rate Plan tab reads/writes) for a property's seeded room categories.
 *
 * There is no RatePlan Eloquent model — rate plans are stored as a JSON blob on
 * `property_meta_fields`, so this is a plain builder rather than an Eloquent factory.
 */
class RatePlanFactory
{
    /**
     * @var array<string, int>
     */
    private const OCCUPANCY_BASE_PRICES = [
        'Single' => 2000,
        'Double' => 2000,
        'Triple' => 2000,
        'Quad' => 2000,
        'Extra Person' => 800,
        'Extra Child' => 400,
    ];

    /**
     * @var array<int, array{name: string, color: string, mapping_status: string, active: bool}>
     */
    private const PLAN_TEMPLATES = [
        ['name' => 'Best Available Rate', 'color' => '#1b9cfc', 'mapping_status' => 'default', 'active' => true],
        ['name' => 'Corporate Rate', 'color' => '#10b981', 'mapping_status' => 'unmapped', 'active' => true],
        ['name' => 'Non-Refundable Rate', 'color' => '#ef4444', 'mapping_status' => 'unmapped', 'active' => false],
    ];

    /**
     * @return array{plans: array<int, array<string, mixed>>}
     */
    public static function make(Property $property): array
    {
        $rows = self::rateRows($property);
        $plans = [];

        foreach (self::PLAN_TEMPLATES as $index => $template) {
            $plans[] = [
                'id' => $index + 1,
                'color' => $template['color'],
                'name' => $template['name'],
                'mapping_status' => $template['mapping_status'],
                'active' => $template['active'],
                'day_wise_breakup' => true,
                'rates' => [
                    'direct' => self::ratesForRows($rows, $index),
                ],
            ];
        }

        return ['plans' => $plans];
    }

    /**
     * @return array<int, array{rowKey: string, occupancy: string, categoryIndex: int}>
     */
    private static function rateRows(Property $property): array
    {
        $rows = [];

        $categories = $property->roomCategories()->orderBy('display_order')->get(['id', 'meal_plans', 'max_adults']);

        foreach ($categories as $categoryIndex => $category) {
            $mealPlans = ! empty($category->meal_plans) ? $category->meal_plans : ['EP'];

            foreach ($mealPlans as $mealPlan) {
                foreach (self::occupancyLabels((int) $category->max_adults) as $occupancy) {
                    $rows[] = [
                        'rowKey' => "{$category->id}:{$mealPlan}:{$occupancy}",
                        'occupancy' => $occupancy,
                        'categoryIndex' => $categoryIndex,
                    ];
                }
            }
        }

        return $rows;
    }

    /**
     * @return array<int, string>
     */
    private static function occupancyLabels(int $maxAdults): array
    {
        $byMaxAdults = [
            1 => ['Single'],
            2 => ['Single', 'Double'],
            3 => ['Single', 'Double', 'Triple'],
            4 => ['Single', 'Double', 'Triple', 'Quad'],
        ];

        $labels = $byMaxAdults[max(1, min(4, $maxAdults))] ?? $byMaxAdults[1];

        return [...$labels, 'Extra Person', 'Extra Child'];
    }

    /**
     * @param  array<int, array{rowKey: string, occupancy: string, categoryIndex: int}>  $rows
     * @return array<string, array<string, int>>
     */
    private static function ratesForRows(array $rows, int $planIndex): array
    {
        $rates = [];

        foreach ($rows as $row) {
            $base = (self::OCCUPANCY_BASE_PRICES[$row['occupancy']] ?? 2000)
                + ($row['categoryIndex'] * 300)
                + ($planIndex * 500);

            $rates[$row['rowKey']] = [
                'mon' => $base,
                'tue' => $base,
                'wed' => $base,
                'thu' => $base,
                'fri' => $base + 200,
                'sat' => $base + 500,
                'sun' => $base + 300,
            ];
        }

        return $rates;
    }
}
