<?php

namespace App\Services\Channex;

use App\Models\ChannexConnection;
use App\Models\ChannexRateMapping;
use App\Models\ChannexRoomMapping;
use App\Services\BookingEngineService;
use App\Services\RatePackages;
use App\Services\RoomInventory;
use App\Services\StayRestrictions;
use Carbon\CarbonImmutable;

/**
 * Pushes ARI (availability, rates, restrictions) to Channex: rooms left per mapped category,
 * night by night, and per mapped rate row its OTA rate from Rate Setup (or its package's rate)
 * with the stay restrictions of its category (Rate Settings → Restrictions). Runs of equal
 * values are sent as one date range.
 */
class ChannexAri
{
    public function __construct(private RoomInventory $inventory, private BookingEngineService $rates, private RatePackages $packages, private StayRestrictions $restrictions) {}

    /**
     * Pushes availability and/or rates for the nights from $from up to $to (inclusive),
     * clamped to today … today + the connection's sync days.
     */
    public function push(ChannexConnection $connection, ?string $from = null, ?string $to = null, bool $availability = true, bool $rates = true): void
    {
        $connection->loadMissing('property');
        [$start, $end] = $this->window($connection, $from, $to);

        if ($start->gt($end)) {
            return;
        }

        $client = ChannexClient::for($connection);

        try {
            if ($availability && ($values = $this->availabilityValues($connection, $start, $end)) !== []) {
                $client->updateAvailability($values);
            }

            if ($rates && ($values = $this->rateValues($connection, $start, $end)) !== []) {
                $client->updateRestrictions($values);
            }
        } catch (ChannexException $exception) {
            $connection->forceFill(['last_error' => $exception->getMessage()])->save();

            throw $exception;
        }

        $connection->forceFill(['last_pushed_at' => now(), 'last_error' => null])->save();
    }

    /**
     * @return list<array{property_id: string, room_type_id: string, date_from: string, date_to: string, availability: int}>
     */
    public function availabilityValues(ChannexConnection $connection, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $mappings = $connection->roomMappings()->get();

        if ($mappings->isEmpty()) {
            return [];
        }

        $capacity = $this->inventory->capacityByCategory($connection->property_id);
        $byNight = [];

        for ($night = $start; $night->lte($end); $night = $night->addDay()) {
            $byNight[$night->toDateString()] = $this->inventory->availabilityByCategory($connection->property_id, $capacity, $night->toDateString(), $night->addDay()->toDateString());
        }

        $values = [];

        foreach ($mappings as $mapping) {
            /** @var ChannexRoomMapping $mapping */
            $nights = array_map(fn ($left): int => (int) ($left[$mapping->room_category_id] ?? 0), $byNight);

            foreach ($this->ranges($nights) as [$dateFrom, $dateTo, $count]) {
                $values[] = [
                    'property_id' => $connection->channex_property_id,
                    'room_type_id' => $mapping->channex_room_type_id,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'availability' => $count,
                ];
            }
        }

        return $values;
    }

    /**
     * Each night's rate (left out when Rate Setup has none: Channex keeps what it has) and
     * restrictions: min stay on arrival (1 = none), max stay (0 = none), closed to arrival /
     * departure and stop-sell. A package rate plan stops selling on nights its calendar
     * doesn't offer it.
     *
     * @return list<array<string, mixed>>
     */
    public function rateValues(ChannexConnection $connection, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $values = [];
        $mappings = $connection->rateMappings()->with('roomCategory')->get();
        $restricted = $this->restrictions->effective($connection->property_id, array_values($mappings->map(fn (ChannexRateMapping $mapping): int => $mapping->room_category_id)->unique()->all()), $start, $end);

        foreach ($mappings as $mapping) {
            /** @var ChannexRateMapping $mapping */
            if ($mapping->roomCategory === null) {
                continue;
            }

            $nights = [];

            for ($night = $start; $night->lte($end); $night = $night->addDay()) {
                $rate = $mapping->package_id
                    ? $this->packages->nightlyRate($connection->property, $mapping->package_id, $mapping->roomCategory, $mapping->meal_plan, $mapping->adults, $night)
                    : $this->rates->nightlyRate($connection->property, $mapping->roomCategory, $mapping->meal_plan, $mapping->adults, $night, 'ota');
                $rules = $restricted[$night->toDateString()][$mapping->room_category_id] ?? [];

                $nights[$night->toDateString()] = [
                    ...($rate === null ? [] : ['rate' => number_format($rate, 2, '.', '')]),
                    'min_stay_arrival' => $rules['min_stay'] ?? 1,
                    'max_stay' => $rules['max_stay'] ?? 0,
                    'closed_to_arrival' => $rules['closed_to_arrival'] ?? false,
                    'closed_to_departure' => $rules['closed_to_departure'] ?? false,
                    'stop_sell' => ($rules['stop_sell'] ?? false) || ($mapping->package_id && $rate === null),
                ];
            }

            foreach ($this->ranges($nights) as [$dateFrom, $dateTo, $night]) {
                $values[] = [
                    'property_id' => $connection->channex_property_id,
                    'rate_plan_id' => $mapping->channex_rate_plan_id,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    ...$night,
                ];
            }
        }

        return $values;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(ChannexConnection $connection, ?string $from, ?string $to): array
    {
        $today = CarbonImmutable::parse($connection->property->today());
        $last = $today->addDays(max(1, $connection->sync_days) - 1);
        $start = $from ? CarbonImmutable::parse($from)->max($today) : $today;
        $end = $to ? CarbonImmutable::parse($to)->min($last) : $last;

        return [$start->startOfDay(), $end->startOfDay()];
    }

    /**
     * Consecutive nights with the same value as [date_from, date_to, value] (dates inclusive).
     *
     * @param  array<string, mixed>  $nights  value by Y-m-d, in date order
     * @return list<array{0: string, 1: string, 2: mixed}>
     */
    private function ranges(array $nights): array
    {
        $ranges = [];

        foreach ($nights as $date => $value) {
            $last = array_key_last($ranges);

            if ($last !== null && $ranges[$last][2] === $value && CarbonImmutable::parse($ranges[$last][1])->addDay()->toDateString() === $date) {
                $ranges[$last][1] = $date;

                continue;
            }

            $ranges[] = [$date, $date, $value];
        }

        return $ranges;
    }
}
