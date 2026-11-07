<?php

namespace Database\Factories;

use Carbon\CarbonImmutable;

/**
 * Builds a `rate_settings_rate_planner` JSON payload (the BAR calendar's
 * day -> rate plan assignments) covering the next 6 months.
 *
 * There is no RatePlanner Eloquent model — this mirrors RatePlanFactory in
 * being a plain builder rather than an Eloquent factory.
 */
class RatePlannerFactory
{
    /**
     * @param  array<int, array{id: int, active: bool}>  $plans
     * @return array{assignments: array<string, int>}
     */
    public static function make(array $plans): array
    {
        $activePlanIds = array_values(array_map(
            fn (array $plan): int => $plan['id'],
            array_filter($plans, fn (array $plan): bool => $plan['active']),
        ));

        if ($activePlanIds === []) {
            return ['assignments' => []];
        }

        $assignments = [];
        $start = CarbonImmutable::now()->startOfDay();
        $end = $start->addMonths(6);

        for ($date = $start; $date->lt($end); $date = $date->addDay()) {
            $weekIndex = (int) floor($start->diffInDays($date) / 7);
            $assignments[$date->format('Y-m-d')] = $activePlanIds[$weekIndex % count($activePlanIds)];
        }

        return ['assignments' => $assignments];
    }
}
