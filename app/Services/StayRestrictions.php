<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Property;
use App\Models\RoomCategory;
use App\Models\StayRestriction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Rate Settings → Restrictions: per date, for the whole property or one room category —
 * minimum and maximum stay and closed to arrival (for stays arriving that day), closed to
 * departure (stays leaving that day) and stop-sell (any stay including the night). A room
 * category's own restriction adds to the property-wide one: the stricter value counts.
 *
 * Enforced on the booking engine and in the booking form (where staff with the permission may
 * override), and pushed to the channel manager.
 */
class StayRestrictions
{
    public const FIELDS = ['min_stay', 'max_stay', 'closed_to_arrival', 'closed_to_departure', 'stop_sell'];

    /**
     * Each date's effective restrictions per room category over [from, to] (inclusive).
     *
     * @param  list<int>  $categoryIds
     * @return array<string, array<int, array{min_stay: int|null, max_stay: int|null, closed_to_arrival: bool, closed_to_departure: bool, stop_sell: bool}>> date => category id => restrictions
     */
    public function effective(int $propertyId, array $categoryIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = StayRestriction::query()
            ->where('property_id', $propertyId)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->where(fn ($query) => $query->whereNull('room_category_id')->orWhereIn('room_category_id', $categoryIds))
            ->get()
            ->groupBy(fn (StayRestriction $row): string => $row->date->toDateString());

        $effective = [];

        foreach ($rows as $date => $dayRows) {
            $propertyWide = $dayRows->firstWhere('room_category_id', null);

            foreach ($categoryIds as $categoryId) {
                $merged = self::merge($propertyWide, $dayRows->firstWhere('room_category_id', $categoryId));

                if ($merged !== null) {
                    $effective[$date][$categoryId] = $merged;
                }
            }
        }

        return $effective;
    }

    /**
     * Why the stay can't be sold in the room category, or null when it can.
     */
    public function violation(int $propertyId, int $categoryId, string $checkIn, string $checkOut): ?string
    {
        $arrival = CarbonImmutable::parse($checkIn)->startOfDay();
        $departure = CarbonImmutable::parse($checkOut)->startOfDay();
        $nights = max(1, (int) $arrival->diffInDays($departure));
        $byDate = $this->effective($propertyId, [$categoryId], $arrival, $departure);
        $on = fn (CarbonImmutable $date): ?array => $byDate[$date->toDateString()][$categoryId] ?? null;

        if ($on($arrival)['closed_to_arrival'] ?? false) {
            return 'Arrivals are closed on '.$arrival->format('d M Y').'.';
        }

        if ($on($departure)['closed_to_departure'] ?? false) {
            return 'Departures are closed on '.$departure->format('d M Y').'.';
        }

        if (($min = $on($arrival)['min_stay'] ?? null) !== null && $nights < $min) {
            return "Stays arriving on {$arrival->format('d M Y')} are at least {$min} nights.";
        }

        if (($max = $on($arrival)['max_stay'] ?? null) !== null && $nights > $max) {
            return "Stays arriving on {$arrival->format('d M Y')} are at most {$max} nights.";
        }

        for ($night = $arrival; $night->lt($departure); $night = $night->addDay()) {
            if ($on($night)['stop_sell'] ?? false) {
                return 'Not on sale for the night of '.$night->format('d M Y').'.';
            }
        }

        return null;
    }

    /**
     * Sets the restrictions on the dates from $from to $to (inclusive) and weekdays (0 = Sunday;
     * empty: every day), for the categories (empty: property-wide). A null value clears that
     * restriction; a row left with nothing restricted is removed.
     *
     * @param  list<int>  $categoryIds
     * @param  list<int>  $weekdays
     * @param  array<string, mixed>  $values  some of FIELDS
     * @return int how many dates were changed
     */
    public function set(Property $property, array $categoryIds, CarbonImmutable $from, CarbonImmutable $to, array $weekdays, array $values, ?User $user = null): int
    {
        $values = array_intersect_key($values, array_flip(self::FIELDS));
        $targets = $categoryIds === [] ? [null] : $categoryIds;
        $changed = 0;

        DB::transaction(function () use ($property, $targets, $from, $to, $weekdays, $values, &$changed): void {
            for ($date = $from; $date->lte($to); $date = $date->addDay()) {
                if ($weekdays !== [] && ! in_array((int) $date->format('w'), $weekdays, true)) {
                    continue;
                }

                foreach ($targets as $categoryId) {
                    $row = StayRestriction::query()
                        ->where('property_id', $property->id)
                        ->when($categoryId === null, fn ($query) => $query->whereNull('room_category_id'), fn ($query) => $query->where('room_category_id', $categoryId))
                        ->whereDate('date', $date->toDateString())
                        ->first()
                        ?? new StayRestriction(['property_id' => $property->id, 'room_category_id' => $categoryId, 'date' => $date->toDateString()]);
                    $row->fill([
                        ...$values,
                        'closed_to_arrival' => (bool) ($values['closed_to_arrival'] ?? $row->closed_to_arrival),
                        'closed_to_departure' => (bool) ($values['closed_to_departure'] ?? $row->closed_to_departure),
                        'stop_sell' => (bool) ($values['stop_sell'] ?? $row->stop_sell),
                    ]);

                    $nothingRestricted = $row->min_stay === null && $row->max_stay === null && ! $row->closed_to_arrival && ! $row->closed_to_departure && ! $row->stop_sell;

                    if (! $nothingRestricted) {
                        $row->save();
                    } elseif ($row->exists) {
                        $row->delete();
                    }
                }

                $changed++;
            }
        });

        if ($user !== null && $changed > 0) {
            $scope = $categoryIds === [] ? 'all room categories' : RoomCategory::query()->whereIn('id', $categoryIds)->pluck('name')->implode(', ');
            ActivityLog::create([
                'user_id' => $user->id,
                'property_id' => $property->id,
                'action' => "Stay restrictions set for {$scope}, {$from->format('d M Y')} to {$to->format('d M Y')}: ".$this->describe($values),
                'date' => now(),
            ]);
        }

        return $changed;
    }

    /**
     * Clears the restrictions on the dates: the categories' own, or with no categories given
     * every restriction there (property-wide and each category's).
     *
     * @param  list<int>  $categoryIds
     */
    public function clear(Property $property, array $categoryIds, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return StayRestriction::query()
            ->where('property_id', $property->id)
            ->when($categoryIds !== [], fn ($query) => $query->whereIn('room_category_id', $categoryIds))
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->delete();
    }

    /**
     * The stored rows over a period, for the calendar.
     *
     * @return Collection<int, StayRestriction>
     */
    public function rows(int $propertyId, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        return StayRestriction::query()
            ->where('property_id', $propertyId)
            ->whereDate('date', '>=', $from->toDateString())
            ->whereDate('date', '<=', $to->toDateString())
            ->orderBy('date')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function describe(array $values): string
    {
        $parts = [];
        $labels = ['min_stay' => 'min stay', 'max_stay' => 'max stay'];

        foreach (['min_stay', 'max_stay'] as $field) {
            if (array_key_exists($field, $values)) {
                $parts[] = $labels[$field].' '.($values[$field] ?? 'cleared');
            }
        }

        foreach (['closed_to_arrival' => 'closed to arrival', 'closed_to_departure' => 'closed to departure', 'stop_sell' => 'stop sell'] as $field => $label) {
            if (array_key_exists($field, $values)) {
                $parts[] = $label.' '.($values[$field] ? 'on' : 'off');
            }
        }

        return implode(', ', $parts) ?: 'no change';
    }

    /**
     * The stricter of the property-wide and the category's own restrictions.
     *
     * @return array{min_stay: int|null, max_stay: int|null, closed_to_arrival: bool, closed_to_departure: bool, stop_sell: bool}|null
     */
    private static function merge(?StayRestriction $propertyWide, ?StayRestriction $category): ?array
    {
        if ($propertyWide === null && $category === null) {
            return null;
        }

        $values = fn (string $field): array => array_values(array_filter([$propertyWide?->{$field}, $category?->{$field}], fn ($value): bool => $value !== null));

        return [
            'min_stay' => $values('min_stay') === [] ? null : max($values('min_stay')),
            'max_stay' => $values('max_stay') === [] ? null : min($values('max_stay')),
            'closed_to_arrival' => (bool) ($propertyWide?->closed_to_arrival || $category?->closed_to_arrival),
            'closed_to_departure' => (bool) ($propertyWide?->closed_to_departure || $category?->closed_to_departure),
            'stop_sell' => (bool) ($propertyWide?->stop_sell || $category?->stop_sell),
        ];
    }
}
